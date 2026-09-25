<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

function allCollectedEvents(CollectingExporter $collector): Collection
{
    Telemetry::flush();

    return collect($collector->batches())->flatMap(fn ($b) => $b->events)->values();
}

it('emits exactly one OTLP log for report() when the telemetry channel is in the log stack', function () {
    report(new RuntimeException('CANARY'));

    $events = allCollectedEvents($this->collector);

    // The structured exception record survives...
    expect($events->where('name', 'exception'))->toHaveCount(1)
        // ...and Laravel's trailing default exception log ('CANARY') is
        // not a second, less structured OTLP log record.
        ->and($events->where('name', 'CANARY'))->toBeEmpty()
        ->and($events)->toHaveCount(1);
});

it('annotates the active span rather than emitting a third standalone OTLP log', function () {
    Telemetry::span('boom-span', function () {
        report(new RuntimeException('CANARY'));
    });

    $events = allCollectedEvents($this->collector);
    $spans = collect($this->collector->batches())->flatMap(fn ($b) => $b->spans);

    expect($events)->toHaveCount(1)
        ->and($spans->firstWhere('name', 'boom-span')->events())->not->toBeEmpty();
});

it('still ships ordinary logs around a reported exception', function () {
    Log::info('plain info');
    report(new RuntimeException('CANARY'));
    Log::error('plain error');

    $events = allCollectedEvents($this->collector);

    expect($events->where('name', 'exception'))->toHaveCount(1)
        ->and($events->where('name', 'CANARY'))->toBeEmpty()
        ->and($events->where('name', 'plain info'))->toHaveCount(1)
        ->and($events->where('name', 'plain error'))->toHaveCount(1)
        ->and($events)->toHaveCount(3);
});
