<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    // Production shape: the telemetry channel rides in the app's default
    // LOG_STACK, and exception instrumentation is on (the default).
    config()->set('logging.default', 'stack');
    config()->set('logging.channels.stack', [
        'driver' => 'stack',
        'channels' => ['telemetry'],
    ]);
    config()->set('logging.channels.telemetry', ['driver' => 'telemetry', 'level' => 'debug']);

    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

function allScenarioEvents(CollectingExporter $collector): Collection
{
    Telemetry::flush();

    return collect($collector->batches())->flatMap(fn ($b) => $b->events)->values();
}

function scenarioExceptions(Collection $events): Collection
{
    return $events->where('name', 'exception')->values();
}

it('emits exactly one structured exception log for report()', function () {
    report(new RuntimeException('A'));

    $events = allScenarioEvents($this->collector);
    $event = scenarioExceptions($events)->first();

    expect($events)->toHaveCount(1)
        ->and(scenarioExceptions($events))->toHaveCount(1)
        ->and($event->name)->toBe('exception')
        ->and($event->severityText)->toBe('ERROR')
        ->and($event->severityNumber)->toBe(17)
        ->and($event->attributes)->toHaveKey('exception.file')
        ->and($event->attributes)->toHaveKey('exception.line')
        ->and($event->attributes)->toHaveKey('exception.stacktrace')
        ->and($event->attributes)->toHaveKey('exception.group');
});

it('deduplicates repeated report() of the same throwable', function () {
    $e = new RuntimeException('B');

    report($e);
    report($e);

    $events = allScenarioEvents($this->collector);

    // The reportable callback runs on EVERY report() and emits the
    // structured event each time (no event-level dedup — pre-existing).
    expect(scenarioExceptions($events))->toHaveCount(2)
        // The default-logger duplicate is what the fix suppresses.
        ->and($events->where('name', 'B'))->toBeEmpty();
});

it('ships an explicit exception log never reported', function () {
    Log::error('caught', ['exception' => new RuntimeException('boom')]);

    $events = allScenarioEvents($this->collector);
    $event = $events->firstWhere('name', 'caught');

    expect($events)->toHaveCount(1)
        ->and($event)->not->toBeNull()
        ->and($event->attributes)->toHaveKey('exception.type')
        ->and($event->attributes)->toHaveKey('exception.message')
        ->and($event->attributes)->not->toHaveKey('exception.file')
        ->and($event->attributes)->not->toHaveKey('exception.line')
        ->and($event->attributes)->not->toHaveKey('exception.group');
});

it('ships an explicit exception log before report()', function () {
    $e = new RuntimeException('D');

    Log::error('before', ['exception' => $e]);
    report($e);

    $events = allScenarioEvents($this->collector);
    $before = $events->firstWhere('name', 'before');
    $structured = $events->firstWhere('name', 'exception');

    expect($events)->toHaveCount(2)
        ->and($before)->not->toBeNull()
        ->and($before->attributes)->toHaveKey('exception.type')
        ->and($before->attributes)->toHaveKey('exception.message')
        ->and($before->attributes)->not->toHaveKey('exception.group')
        ->and($structured)->not->toBeNull()
        ->and($structured->attributes)->toHaveKey('exception.group')
        ->and($structured->attributes)->toHaveKey('exception.file');
});

it('ships an explicit exception log after report()', function () {
    $e = new RuntimeException('E');

    report($e);
    Log::error('after', ['exception' => $e]);

    $events = allScenarioEvents($this->collector);

    // report()'s trailing default-logger pass consumed the mark, so the
    // explicit 'after' log ships.
    expect($events)->toHaveCount(2)
        ->and($events->where('name', 'exception'))->toHaveCount(1)
        ->and($events->where('name', 'after'))->toHaveCount(1);
});

it('keeps two identical throwables independent', function () {
    $a = new RuntimeException('same');
    $b = new RuntimeException('same');

    report($a);
    report($b);

    $events = allScenarioEvents($this->collector);

    // Different object identity: no cross-suppression.
    expect(scenarioExceptions($events))->toHaveCount(2)
        ->and($events->where('name', 'same'))->toBeEmpty();
});

it('handles exception chains', function () {
    $inner = new RuntimeException('inner');
    $outer = new RuntimeException('outer', 0, $inner);

    report($outer);

    $events = allScenarioEvents($this->collector);

    expect(scenarioExceptions($events))->toHaveCount(1)
        ->and($events->where('name', 'outer'))->toBeEmpty();
});

it('keeps multiple unrelated exceptions independent in one process', function () {
    report(new RuntimeException('h1'));
    report(new RuntimeException('h2'));

    $events = allScenarioEvents($this->collector);

    expect(scenarioExceptions($events))->toHaveCount(2)
        ->and($events->where('name', 'h1'))->toBeEmpty()
        ->and($events->where('name', 'h2'))->toBeEmpty();
});

it('clears deduplication state across lifecycle boundaries', function () {
    // Job #1 in the worker process.
    report(new RuntimeException('job1'));
    Telemetry::flush();

    // Worker boundary: Octane request / next queue job.
    Telemetry::resetContext();

    $collector2 = new CollectingExporter;
    Telemetry::addExporter($collector2);

    // Job #2, same message — must NOT be suppressed by job #1's state.
    report(new RuntimeException('job1'));

    $events = allScenarioEvents($collector2);

    expect(scenarioExceptions($events))->toHaveCount(1)
        ->and($events->where('name', 'job1'))->toBeEmpty();
});

it('does not stop Laravel exception reporting chain', function () {
    $counter = new stdClass;
    $counter->count = 0;

    app(ExceptionHandler::class)->reportable(function (Throwable $e) use ($counter): void {
        $counter->count++;
    });

    report(new RuntimeException('J'));

    expect($counter->count)->toBe(1);
});

it('does not suppress a non-telemetry Monolog handler', function () {
    config()->set('logging.channels.stack', [
        'driver' => 'stack',
        'channels' => ['telemetry', 'testbuf'],
    ]);
    config()->set('logging.channels.testbuf', [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'level' => 'debug',
    ]);

    report(new RuntimeException('K'));

    $handlers = Log::channel('testbuf')->getLogger()->getHandlers();
    $testHandler = $handlers[0];

    expect($testHandler)->toBeInstanceOf(TestHandler::class)
        ->and($testHandler->hasErrorRecords())->toBeTrue();
});

it('keeps traceId/spanId correlation on the surviving exception event', function () {
    $span = null;

    Telemetry::span('L-span', function ($s) use (&$span): void {
        $span = $s;
        report(new RuntimeException('L'));
    });

    $events = allScenarioEvents($this->collector);
    $event = $events->firstWhere('name', 'exception');

    expect($event)->not->toBeNull()
        ->and($event->traceId)->not->toBeNull()
        ->and($event->spanId)->not->toBeNull()
        ->and($event->traceId)->toBe($span->traceId)
        ->and($event->spanId)->toBe($span->spanId);
});
