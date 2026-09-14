# Records

- [Introduction](#introduction)
- [The Record Shape](#the-record-shape)
    - [Fields on Every Point Event](#fields-on-every-point-event)
    - [Fields by Event](#fields-by-event)
    - [Breaker Events](#breaker-events)
- [Events and Levels](#events-and-levels)
- [The Message](#the-message)
- [Redaction](#redaction)
- [Exception Traces](#exception-traces)
- [The Channel](#the-channel)
- [NDJSON with the JSON Tap](#ndjson-with-the-json-tap)
- [The Origin-Bound Logger](#the-origin-bound-logger)
- [Events](#events)
- [The Schema File](#the-schema-file)

## Introduction

Every transition of a control point produces one record: it started, it was retried, it breached a limit, it recovered, it escalated, it was refused, it ended. Every circuit breaker transition produces one too. A record is written as a log line whose context carries a fixed set of fields, so a log backend indexes `point`, `domain` and `status` as fields rather than finding them inside free text.

The fields, the event names and the status values are fixed by the schema at `resources/schema/record-1.json`, versioned as `monitor/1`. Nothing downstream needs to parse a message.

## The Record Shape

A record is the context array of one log line. The `Recorder` subscribes to Monitor's events and writes one line per event; the message is human readable, and everything a machine needs is in the context.

### Fields on Every Point Event

| Field | Type | Meaning |
| --- | --- | --- |
| `schema` | string | Always `monitor/1`. |
| `event` | string | One of the event names below. |
| `point` | string | The control point name, e.g. `payment.charge`. |
| `run_id` | string | ULID of this run. |
| `parent_run_id` | string or null | ULID of the enclosing run when the point is nested inside another. |
| `stack` | list of strings | Point names from the outermost to this one. |
| `trace_id` | string | 32 lowercase hex characters. See [Tracing](tracing.md). |
| `domain` | string | The business area the point belongs to, derived from its origin's namespace. |
| `origin` | string | The fully qualified class the point belongs to. |
| `profile` | string or null | The profile the point started from, if any. |
| `context` | object | The point's context, redacted. |

### Fields by Event

| Event | Extra fields |
| --- | --- |
| `point.started` | `attempt` (always `1`). |
| `point.retried` | `attempt` (the attempt that failed), `backoff_ms`, `exception`. |
| `point.limit` | `limit` with `name` (`duration` or `attempts`), `threshold` and `actual`. |
| `point.recovered` | `status`, `attempts`, `duration_ms`, `limits_breached`, `exception`, `risk` (the exception class the correction handled). |
| `point.escalated` | `status`, `attempts`, `duration_ms`, `limits_breached`, `exception`. |
| `point.refused` | `status`, `attempts`, `duration_ms`, `limits_breached`, `exception`, `breaker` with `name`, `state` and `retry_after_s`. |
| `point.ended` | `status`, `attempts`, `duration_ms`, `limits_breached`, and `exception` when the run failed. |
| `escalation.failed` | Everything `point.ended` carries, plus `escalation_exception`: the exception the escalation handler itself threw. The original failure still propagates. |

`status` is one of `succeeded`, `recovered`, `escalated` or `refused`. `limits_breached` is an object keyed by limit name, each holding `threshold` and `actual`; it is empty when nothing was breached.

`exception` is a summary, never the object: `class`, `message`, `file`, `line`, `code`, an optional `previous` with the class and message one level down, and a `trace` only when [configured](#exception-traces).

Every outcome event is followed by `point.ended`, so a consumer that only wants one line per run listens for `point.ended` and reads `status`.

### Breaker Events

`breaker.opened`, `breaker.half_open` and `breaker.closed` carry `schema`, `event` and a `breaker` object with `name`, `state` (`closed`, `open` or `half_open`), `failures` (failures inside the window) and `open_for` (seconds the circuit was opened for, or null). See [Breakers](breakers.md).

## Events and Levels

Each event is written at the level in `records.levels`. The defaults:

| Event | Level |
| --- | --- |
| `point.started` | debug |
| `point.retried` | notice |
| `point.limit` | warning |
| `point.recovered` | warning |
| `point.refused` | warning |
| `point.escalated` | error |
| `point.ended` | info |
| `escalation.failed` | critical |
| `breaker.opened` | error |
| `breaker.half_open` | notice |
| `breaker.closed` | info |

Change any of them in `config/monitor.php`:

```php
'records' => [
    'levels' => [
        'point.started' => 'debug',
        'point.ended' => 'notice',
        // ...
    ],
],
```

An event missing from the map is written at `info`.

## The Message

The message is for a person tailing a file. It starts with `[Domain:Origin]`, where Origin is the short class name, then the point name, then what happened:

```
[Payments:StripeCharger] payment.charge started
[Payments:StripeCharger] payment.charge attempt 1 failed with App\Exceptions\GatewayTimeout, retrying after 200ms
[Payments:StripeCharger] payment.charge breached the duration limit: 14012.4 against 5000
[Payments:StripeCharger] payment.charge recovered from App\Exceptions\CardDeclined in 893.2ms
[Payments:StripeCharger] payment.charge escalated App\Exceptions\GatewayDown after 3 attempt(s) in 4120ms
[Payments:StripeCharger] payment.charge refused: breaker stripe is open
[Payments:StripeCharger] payment.charge succeeded in 412ms
[Payments:StripeCharger] payment.charge escalation handler threw LogicException; the original failure still propagates
[Monitor] breaker stripe is now open
```

## Redaction

Before a record is written, its `context` and the `message` of any exception summary go through [Kirschbaum Redactor](https://github.com/kirschbaum-development/redactor) with the profile in `records.redaction`. The default is Redactor's `observability` profile, which is built for this: it replaces credentials and personal data and leaves identifiers alone, so records stay joinable on `run_id`, `trace_id` and the like. No `_redacted` marker is added.

Only application data is redacted. Point names, run ids, trace ids, domains and origins are never touched.

```php
'records' => [
    'redaction' => env('MONITOR_REDACTION_PROFILE', 'observability'),
],
```

Set it to `null` or an empty string to write context and messages as they are. Any other value must be a profile Redactor knows.

## Exception Traces

By default an exception summary has no stack trace. `records.exception_trace` controls that:

| Value | Behaviour |
| --- | --- |
| `never` | No trace. The default. |
| `debug` | A trace when `app.debug` is true. |
| `always` | A trace in every environment. |

When a trace is included it is cut to `records.exception_trace_lines` lines (15 by default), and `exception.trace_truncated` says how many were dropped.

```php
'records' => [
    'exception_trace' => env('MONITOR_EXCEPTION_TRACE', 'never'),
    'exception_trace_lines' => 15,
],
```

## The Channel

Records go to the application's default log channel unless `records.channel` names another:

```php
'records' => [
    'channel' => env('MONITOR_LOG_CHANNEL'),
],
```

The channel must exist in `config/logging.php`. Laravel's [Context](https://laravel.com/docs/context) is attached to every record like any other log line, so the `trace_id` and `control_point` that Monitor keeps there appear in the record's extra data too.

## NDJSON with the JSON Tap

Laravel's default formatter writes the message followed by the context as JSON. For a backend that wants one JSON object per line with the record's fields at the top level, add the tap to the channel:

```php
'channels' => [
    'monitor' => [
        'driver' => 'daily',
        'path' => storage_path('logs/monitor.log'),
        'level' => 'debug',
        'days' => 14,
        'tap' => [Kirschbaum\Monitor\Logging\JsonTap::class],
    ],
],
```

`JsonTap` installs `RecordFormatter` on every formattable handler of the channel. The formatter writes `ts`, `level`, `channel` and `message` first, then every key from the record's extra data, then every context key; a context key wins over an extra key of the same name. A line that cannot be encoded is replaced rather than dropped, so a record with bad bytes still leaves a line.

```json
{"ts":"2026-09-14T18:10:25.011+00:00","level":"warning","channel":"monitor","message":"[Payments:StripeCharger] payment.charge recovered from App\\Exceptions\\CardDeclined in 893.2ms","schema":"monitor/1","event":"point.recovered","point":"payment.charge","run_id":"01K5A3ZR7Q8V1J3M0X6Y2N9WCD","parent_run_id":null,"stack":["payment.charge"],"trace_id":"7f3a2c1d9e8b4a6f0c5d1e2f3a4b5c6d","domain":"Payments","origin":"App\\Services\\Payments\\StripeCharger","profile":"external","context":{"invoice":48211,"amount":12900},"status":"recovered","attempts":2,"duration_ms":893.2,"limits_breached":{},"exception":{"class":"App\\Exceptions\\CardDeclined","message":"Card declined: insufficient_funds","file":"/app/app/Services/Payments/StripeCharger.php","line":41,"code":0},"risk":"App\\Exceptions\\CardDeclined"}
```

Point the channel at `records.channel` and every record lands there in this shape. The tap is also fine on a channel the rest of the application writes to; an ordinary log line simply has fewer fields.

## The Origin-Bound Logger

Records cover control points. For any other line you want grouped by domain, `Monitor::log()` returns a PSR-3 logger bound to an origin:

```php
use Kirschbaum\Monitor\Facades\Monitor;

Monitor::log($this)->info('Index rebuilt', ['documents' => 4120]);
```

The origin is the class of the object passed, or the string passed. The line is written as:

```
[Search:Indexer] Index rebuilt {"documents":4120,"origin":"App\\Services\\Search\\Indexer","domain":"Search"}
```

The prefix is `[Domain:ShortClass]`, and `origin` and `domain` are added to the context so a backend can filter on them. Domain resolution is the same as for control points; see [Configuration](configuration.md).

`with()` returns a logger that adds context to every line it writes, and `channel()` one that writes to a named channel:

```php
$log = Monitor::log($this)->with(['tenant' => $tenant->id])->channel('search');

$log->info('Index rebuilt');
$log->warning('Index stale', ['age_minutes' => 42]);
```

Both return new instances; the original is unchanged. The logger implements `Psr\Log\LoggerInterface`, so it can be handed to anything that accepts one, and uses `Conditionable`, so `when()` and `unless()` work on it. A level that is neither a string nor `Stringable` is written at `info`.

## Events

Every record is written by a listener, and the events are public. Alerting, metrics and anything else that reacts to a control point should listen to the events rather than to log lines.

| Event | Carries |
| --- | --- |
| `Kirschbaum\Monitor\Events\PointStarted` | `run`: a `RunInfo` with the point, run id, parent id, trace id, domain, origin, profile, stack and context. |
| `Kirschbaum\Monitor\Events\PointRetried` | `run`, `exception`, `attempt` (the attempt that failed), `backoffMs`. |
| `Kirschbaum\Monitor\Events\PointLimitBreached` | `run`, `limit` (`duration` or `attempts`), `threshold`, `actual`. |
| `Kirschbaum\Monitor\Events\PointRecovered` | `outcome`. |
| `Kirschbaum\Monitor\Events\PointEscalated` | `outcome`. |
| `Kirschbaum\Monitor\Events\PointRefused` | `outcome`. |
| `Kirschbaum\Monitor\Events\PointEnded` | `outcome`. Dispatched after the status event, for every run. |
| `Kirschbaum\Monitor\Events\EscalationFailed` | `outcome`, `exception`: what the escalation handler threw. |
| `Kirschbaum\Monitor\Events\BreakerOpened` | `breaker` (the name), `state`: a `BreakerState`. |
| `Kirschbaum\Monitor\Events\BreakerHalfOpen` | `breaker`, `state`. |
| `Kirschbaum\Monitor\Events\BreakerClosed` | `breaker`, `state`. |

`Outcome` is described in [Getting Started](getting-started.md#the-outcome). `Kirschbaum\Monitor\RunInfo` is the readonly object the in-progress events carry, with public `point`, `id`, `parentId`, `traceId`, `domain`, `origin`, `profile`, `stack` and `context`; `Outcome::info()` returns the same object for a finished run. A listener that pages on escalation:

```php
use Kirschbaum\Monitor\Events\PointEscalated;

Event::listen(PointEscalated::class, function (PointEscalated $event): void {
    if ($event->outcome->domain === 'Payments') {
        Pager::page('payments', $event->outcome->toArray());
    }
});
```

Recovered runs do not dispatch `PointEscalated`, and a point's own `escalate()` handler is separate from these events; see [Risks and Corrections](risks-and-corrections.md). The [outcome store](store.md) is itself a listener on `PointEnded`.

## The Schema File

`resources/schema/record-1.json` is a JSON Schema (draft 2020-12) for the record's context. It lists every field, the event enumeration and the status enumeration. It is also served to agents as an MCP resource; see [Agents](agents.md).
