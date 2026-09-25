<?php

declare(strict_types=1);

use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\Testing\CollectingExporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler as CollisionExceptionHandler;

beforeEach(function () {
    // Production shape: the telemetry channel rides the default stack, so
    // Laravel's trailing default-logger pass after the reportables reaches
    // TelemetryLogHandler (the Problem #1 surface).
    config()->set('logging.default', 'stack');
    config()->set('logging.channels.stack', [
        'driver' => 'stack',
        'channels' => ['telemetry'],
    ]);
    config()->set('logging.channels.telemetry', ['driver' => 'telemetry', 'level' => 'debug']);

    $this->collector = new CollectingExporter;
    Telemetry::addExporter($this->collector);
});

/**
 * The lifecycle Collision's console provider runs on every artisan boot
 * (CollisionServiceProvider::register(): it resolves the app's handler,
 * then REBINDS the ExceptionHandler contract to an adapter wrapping that
 * same instance). The container fires the package's afterResolving
 * callback once for the app handler and once for the adapter, which
 * delegates reportable() straight through to the wrapped handler.
 */
function rebindThroughCollision(object $inner): void
{
    $app = app();

    $app->singleton(ExceptionHandler::class, function ($app) use ($inner) {
        return new CollisionExceptionHandler($app, $inner);
    });

    $app->make(ExceptionHandler::class);
}

function telemetryReportables(object $handler): int
{
    $r = new ReflectionObject($handler);
    $p = $r->getProperty('reportCallbacks');
    $p->setAccessible(true);

    $count = 0;
    foreach ($p->getValue($handler) as $reportable) {
        $rp = new ReflectionObject($reportable);
        $cp = $rp->getProperty('callback');
        $cp->setAccessible(true);
        $cb = $cp->getValue($reportable);

        if ($cb instanceof Closure
            && str_ends_with((new ReflectionFunction($cb))->getFileName(), 'TelemetryServiceProvider.php')) {
            $count++;
        }
    }

    return $count;
}

function scenarioEvents(CollectingExporter $collector): Collection
{
    Telemetry::flush();

    return collect($collector->batches())->flatMap(fn ($b) => $b->events)->values();
}

it('arms exactly once across the Collision console rebind lifecycle', function () {
    // Fire #1 happened at boot: the app handler is already armed.
    $inner = app(ExceptionHandler::class);
    expect(telemetryReportables($inner))->toBe(1);

    // Fire #2: the Collision adapter, which delegates reportable() to the
    // same wrapped handler. Without the registration guard this lands a
    // second package reportable on the inner handler and every report()
    // emits two structured exception events.
    rebindThroughCollision($inner);

    expect(telemetryReportables($inner))->toBe(1);
});

it('arms exactly once when the same instance is resolved twice', function () {
    $inner = app(ExceptionHandler::class);
    expect(telemetryReportables($inner))->toBe(1);

    // A rebind whose closure hands back the SAME object: the afterResolving
    // callback fires again with the identical instance.
    app()->singleton(ExceptionHandler::class, fn () => $inner);
    app()->make(ExceptionHandler::class);

    expect(telemetryReportables($inner))->toBe(1);
});

it('instruments each distinct handler instance exactly once', function () {
    $a = app(ExceptionHandler::class);
    expect(telemetryReportables($a))->toBe(1);

    // A fresh framework handler (Octane/worker flushes rebuild the binding):
    // a different instance, armed independently.
    app()->forgetInstance(ExceptionHandler::class);
    $b = app(ExceptionHandler::class);

    expect($b)->not->toBe($a)
        ->and(telemetryReportables($b))->toBe(1)
        ->and(telemetryReportables($a))->toBe(1);
});

it('emits one structured event and no duplicate for report() through the Collision lifecycle', function () {
    $inner = app(ExceptionHandler::class);
    rebindThroughCollision($inner);

    // report() now resolves the adapter; reportThrowable runs on the inner
    // handler with the single armed reportable.
    report(new RuntimeException('console lifecycle'));

    $events = scenarioEvents($this->collector);

    expect($events)->toHaveCount(1)
        ->and($events->where('name', 'exception'))->toHaveCount(1)
        ->and($events->where('name', 'console lifecycle'))->toBeEmpty()
        ->and($events->first()->attributes)->toHaveKeys([
            'exception.type',
            'exception.file',
            'exception.line',
            'exception.stacktrace',
            'exception.group',
        ]);
});

it('emits one structured event for a queue failure through the Collision lifecycle', function () {
    $inner = app(ExceptionHandler::class);
    rebindThroughCollision($inner);

    // Worker::handleJobException() dispatches JobFailed (tearing the job's
    // context down) and rethrows; runJob()'s catch reports THAT throwable
    // through the exception handler. Same instance, one flow.
    app('queue');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SelfFailingJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    $events = app('events');
    $events->dispatch(new JobProcessing('redis', $job));

    $failure = new RuntimeException('queue boom');
    $events->dispatch(new JobFailed('redis', $job, $failure));
    report($failure);

    $all = scenarioEvents($this->collector);

    expect($all)->toHaveCount(1)
        ->and($all->where('name', 'exception'))->toHaveCount(1)
        ->and($all->where('name', 'queue boom'))->toBeEmpty();
});

it('emits one structured event for a reported scheduler failure', function () {
    $inner = app(ExceptionHandler::class);
    rebindThroughCollision($inner);

    // ScheduleRunCommand catches a task's Throwable and reports it
    // (ScheduleRunCommand.php:212) — the same handler path as report().
    report(new RuntimeException('schedule boom'));

    $all = scenarioEvents($this->collector);

    expect($all)->toHaveCount(1)
        ->and($all->where('name', 'exception'))->toHaveCount(1)
        ->and($all->where('name', 'schedule boom'))->toBeEmpty();
});

it('still ships ordinary and explicit exception logs under the Collision lifecycle', function () {
    $inner = app(ExceptionHandler::class);
    rebindThroughCollision($inner);

    Log::error('plain message');
    Log::error('caught', ['exception' => new RuntimeException('never reported')]);

    $all = scenarioEvents($this->collector);

    expect($all)->toHaveCount(2)
        ->and($all->where('name', 'plain message'))->toHaveCount(1)
        ->and($all->where('name', 'caught'))->toHaveCount(1);
});

it('intentionally ignores a non-framework handler exposing reportable()', function () {
    // Duck-typed contract implementations without framework Handler
    // parentage were never a documented or tested integration surface —
    // the previous method_exists() check was incidental. Arming on
    // arbitrary wrappers is exactly what re-registered the reportable
    // under Collision (the adapter delegates reportable() through), so
    // non-framework handlers are deliberately NOT instrumented. This
    // test pins that contract so the trade-off is a decision, not drift.
    $registered = new stdClass;
    $registered->count = 0;

    $duck = new class($registered)
    {
        public function __construct(private readonly stdClass $registered) {}

        public function reportable(callable $reportUsing): void
        {
            $this->registered->count++;
        }
    };

    app()->singleton(ExceptionHandler::class, fn () => $duck);
    app()->make(ExceptionHandler::class);

    expect($registered->count)->toBe(0);
});
