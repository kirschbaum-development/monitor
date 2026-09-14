# Tracing

- [Introduction](#introduction)
- [The Trace ID](#the-trace-id)
- [The Trace API](#the-trace-api)
- [HTTP Requests](#http-requests)
    - [Incoming: the StartTrace Middleware](#incoming-the-starttrace-middleware)
    - [Outgoing: Http::traced()](#outgoing-httptraced)
- [Queued Jobs](#queued-jobs)
- [Console](#console)
- [Every Log Line](#every-log-line)

## Introduction

A trace ID follows one piece of work through every control point it touches, across queued jobs and across services. Every record Monitor writes carries it as `trace_id`, and so does every other log line the application writes while the trace is active, because Monitor keeps it in Laravel's [Context](https://laravel.com/docs/context) rather than in state of its own.

## The Trace ID

A trace ID is 32 lowercase hexadecimal characters, the form the W3C `traceparent` header uses. A UUID is accepted anywhere an ID is passed in and normalised to that form by removing its dashes and lowercasing it. Anything else, including an all-zero ID, is invalid and rejected.

```php
Kirschbaum\Monitor\Trace\Trace::normalise('9D2B4E8F-3A1C-4D5E-8F2A-1B3C4D5E6F7A');
// "9d2b4e8f3a1c4d5e8f2a1b3c4d5e6f7a"

Kirschbaum\Monitor\Trace\Trace::normalise('not a trace');
// null
```

## The Trace API

`Monitor::trace()` returns the `Trace` service.

```php
use Kirschbaum\Monitor\Facades\Monitor;

Monitor::trace()->id();          // the current ID, starting a trace if none exists
Monitor::trace()->current();     // the current ID or null
Monitor::trace()->hasStarted();  // bool

Monitor::trace()->start();       // a new ID, replacing any current one
Monitor::trace()->pickup();      // keep the current one, or start one
Monitor::trace()->pickup($id);   // keep the current one, or adopt $id
Monitor::trace()->override($id); // adopt $id, replacing any current one
Monitor::trace()->clear();       // forget the trace
```

`pickup()` is the safe call: it never replaces a trace that exists. `override()` and `pickup()` with an ID throw `Kirschbaum\Monitor\Exceptions\InvalidTraceId` when the ID is not valid. A control point calls `id()` when it starts, so a point run outside any request or job still has a trace.

The ID lives in Context under the key `trace_id`.

`Trace::generate()` returns a fresh ID without storing it. `Kirschbaum\Monitor\Trace\TraceParent` builds and reads the header for clients the middleware does not cover: `TraceParent::format($traceId)` returns a `00-<trace id>-<span id>-01` header, `TraceParent::parse($header)` returns the trace ID inside a valid one or `null`, and `TraceParent::spanId()` returns a random 16-character span ID.

## HTTP Requests

### Incoming: the StartTrace Middleware

Add `Kirschbaum\Monitor\Http\Middleware\StartTrace` to the middleware stack:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(Kirschbaum\Monitor\Http\Middleware\StartTrace::class);
})
```

To trace only some routes instead, the package registers the same middleware under the `monitor.trace` alias for route groups.

For each request, when no trace has started yet, the middleware looks at the incoming headers in this order:

1. The header in `trace.header`, `traceparent` by default, parsed as a W3C traceparent (`00-<trace id>-<span id>-<flags>`). Its trace ID is adopted.
2. The header in `trace.legacy_header`, `X-Trace-Id` by default, which may hold a 32 hex ID or a UUID. Its normalised value is adopted.
3. Neither present: a new trace starts.

A header that is present but invalid is not adopted. The request gets a fresh trace and one line is written at debug, `[Monitor] replaced an invalid incoming traceparent` or `... trace id`, naming the header. A client sending junk cannot put junk on every log line of the request.

On the way out, both headers are set on the response: `traceparent` in full W3C form with a fresh span ID, and the legacy header with the bare ID, so a caller of either kind can correlate.

```php
'trace' => [
    'header' => 'traceparent',
    'legacy_header' => env('MONITOR_TRACE_HEADER', 'X-Trace-Id'),
],
```

A trace that already started before the middleware runs is kept as it is.

### Outgoing: Http::traced()

The service provider registers a `traced()` macro on Laravel's HTTP client. It adds the current trace to the request as both headers, starting a trace if none exists:

```php
use Illuminate\Support\Facades\Http;

Http::traced()->get('https://service-b.example.com/api/orders');

Http::baseUrl('https://service-c.example.com')
    ->withToken($token)
    ->traced()
    ->post('/filings', $payload);
```

The macro exists on the factory (`Http::traced()`) and on a pending request (`->traced()` anywhere in a chain). Service B, running the same middleware, picks the ID up and its records join yours.

## Queued Jobs

Laravel's Context is serialised with a job when it is dispatched and restored when the job runs, so a job dispatched from inside a trace carries it and every record the job writes shares the ID. Nothing needs to be done for that.

A job that arrives without a trace, because it was dispatched from a process that had none, gets a trace of its own when it starts: Monitor listens to `Illuminate\Queue\Events\JobProcessing` with `Kirschbaum\Monitor\Trace\PicksUpJobTrace`, which calls `pickup()`.

## Console

In console, a trace starts when an artisan command starts, on Laravel's `CommandStarting` event, so a command's records share one ID and nothing runs at provider boot. Turn that off with `trace.console`:

```php
'trace' => [
    'console' => env('MONITOR_TRACE_CONSOLE', true),
],
```

## Every Log Line

Because the ID is kept in Context, Laravel attaches it to every log record the application writes while the trace is active, on every channel, not only to Monitor's records. While a control point runs, Context also carries `control_point`, the name of the innermost point, so an ordinary `Log::info()` inside a point says which point it happened in. Both disappear when the point ends and the trace is cleared.
