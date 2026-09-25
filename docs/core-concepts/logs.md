---
title: Logs
description: Ship Laravel logs as trace-correlated OTLP log records
weight: 5
---

# Logs

The `telemetry` log channel turns ordinary Laravel logging into OTLP log
records — with Monolog severity mapped onto the OTLP range and, crucially,
**correlated to the active trace**, so a log line appears on the exact span
that produced it.

## Setup

Add the channel to your stack in `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'telemetry'],
    ],

    'telemetry' => [
        'driver' => 'telemetry',
        'level' => env('TELEMETRY_LOG_LEVEL', 'info'),
    ],
],
```

Nothing else changes — `Log::info()`, `report()`, `logger()` all flow
through the stack as usual:

```php
Telemetry::span('import.customers', function () {
    Log::info('Import started', ['file' => $path]);
    // → OTLP log record with this span's traceId + spanId
});
```

## What gets exported

| Log record part | OTLP field |
|---|---|
| message | `body` |
| Monolog level | `severityNumber` (DEBUG 5, INFO 9, NOTICE 10, WARNING 13, ERROR 17, CRITICAL 21, ALERT 22, EMERGENCY 23) + `severityText` |
| channel | `log.channel` attribute |
| scalar context | `log.context.*` attributes |
| `exception` context | `exception.type` + `exception.message` attributes |
| other non-scalars | JSON-encoded `log.context.*` |
| active trace | `traceId` + `spanId` |

Records buffer with spans/events and export at terminate to every exporter
supporting the events signal (`/v1/logs` on OTLP).

## Feedback-loop protection

Log records emitted *while a flush is exporting* are dropped — a failing
exporter that reports through the logging stack can never feed itself.

## Exception log deduplication

`report()` runs the package's structured exception instrumentation **first**
(emitting the `exception` OTLP log with `exception.file`/`.line`/
`.stacktrace`/`.group`), then continues to Laravel's own default logger.
When the `telemetry` channel rides in `LOG_STACK`, that default-logger pass
would ship a second, less structured `ERROR <message>` record for the same
throwable.

The handler recognises that trailing pass — by **object identity** (a
`WeakMap` mark set on the throwable by the exception instrumentation) and a
message match against `$e->getMessage()` — and skips **only** it. The mark
is consumed once, so a later explicit
`Log::error('caught', ['exception' => $e])` from application code still
ships. Object identity means two throwables that share class + message +
stack stay independent, and `resetContext()` drops the mark between Octane
requests and queue jobs so no state leaks across the boundary.

## Agent prompt

```text
Wire cboxdk/laravel-telemetry's log channel into this Laravel app:

1. In config/logging.php add:
   'telemetry' => ['driver' => 'telemetry', 'level' => env('TELEMETRY_LOG_LEVEL', 'info')]
   and append 'telemetry' to the channels array of the 'stack' channel.
   If LOG_CHANNEL is not 'stack', ask me before changing it.
2. Leave all existing channels untouched — telemetry is additive.
3. Verify with a Pest test: Telemetry::fake(), then
   Log::channel('telemetry')->warning('test', ['k' => 'v']) and assert via
   $fake->recordedEvents() that severityNumber is 13 and the context
   attribute log.context.k equals 'v'.
4. Note in your summary: records correlate to the active trace
   automatically; nothing else to configure.
```

## Logs vs. events

- **`telemetry` channel** — your existing operational logging, exported.
  Free-text messages, severities, exceptions.
- **`Telemetry::event()`** — deliberate structured records (decisions,
  state transitions) with a stable name you'll query by.

Both end up as OTLP log records; use the channel for what you already log
and events for what you want to *query*.
