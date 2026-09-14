# Breakers

- [Introduction](#introduction)
- [The State Machine](#the-state-machine)
- [Storage](#storage)
- [On a Control Point](#on-a-control-point)
- [On an HTTP Call](#on-an-http-call)
- [The Standalone API](#the-standalone-api)
- [BreakerState](#breakerstate)
- [The Route Middleware](#the-route-middleware)
- [Events](#events)

## Introduction

A circuit breaker stops an application from calling a dependency that is failing, for long enough for it to recover. Monitor's breakers are named circuits shared by every process through the cache. A control point declares which circuit it depends on; the standalone API and the route middleware let anything else consult or drive the same circuit.

## The State Machine

A circuit is in one of three states.

**Closed.** Attempts are allowed. Failures are counted inside a sliding window of `within` seconds. When the count reaches `after`, the circuit opens.

**Open.** Attempts are refused for `for` seconds. A control point refused this way ends `refused` and raises `BreakerOpen`; see [Risks and Corrections](risks-and-corrections.md). When the period ends, the next attempt moves the circuit to half-open.

**Half-open.** Exactly one probe is allowed through. Its success closes the circuit and clears the failures. Its failure opens the circuit again for a full period. A second caller arriving while the probe is in flight is refused.

The three numbers are read as "`after` failures `within` seconds open the circuit `for` seconds". Defaults come from config and can be overridden per circuit:

```php
'breakers' => [
    'after' => 5,
    'within' => 60,
    'for' => 120,
],
```

Failures older than the window are forgotten, so five failures spread over an hour do not open a circuit whose window is a minute.

## Storage

State is kept in the cache so every worker and web process sees the same circuit. `breakers.store` names the cache store to use, or null for the default; `breakers.prefix` is prepended to the circuit name to make the key.

The circuit is only shared as far as the cache store is. On the `array` or `file` driver each process keeps its own circuit and the half-open probe lock protects nothing beyond that process, so a breaker that must be shared across web workers and queue workers needs a shared store such as Redis, Memcached or the database driver. Point `breakers.store` at one when the application's default cache is local.

```php
'breakers' => [
    'store' => env('MONITOR_BREAKER_STORE'),
    'prefix' => 'monitor:breaker:',
],
```

The half-open probe is a cache lock alongside the state, so it works wherever `Cache::add()` does. An entry that cannot be read as a state is treated as closed.

## On a Control Point

A point declares its circuit with `breaker()`:

```php
Monitor::control('payment.charge', $this)
    ->breaker('stripe', after: 3, within: 60, for: 120)
    ->run(fn () => $this->stripe->charge($amount));
```

Omitted numbers fall back to the configured defaults. A profile can carry a breaker too, named after the point unless the profile names one; see [Policies and Limits](policies-and-limits.md).

The breaker is the outermost policy, so it sees one result per run: a point that retried twice and then succeeded is a success, and one that retried and then escalated is one failure. The `Breaker` policy object accepts `on()` and `except()` to say which exception classes count as failures; a declined card is not a gateway outage:

```php
use Kirschbaum\Monitor\Policies\Breaker;

Monitor::control('payment.charge', $this)
    ->policy(Breaker::named('stripe')->after(3, within: 60)->for(120)->except([CardDeclined::class]))
    ->run(...);
```

When the circuit is open the point does not run its callback. The outcome's status is `refused`, its exception is a `Kirschbaum\Monitor\Risks\BreakerOpen` with the circuit's name and the seconds until it can be retried, and `run()` throws it. The point may recover from its own refusal with `recover(BreakerOpen::class, ...)`, and a parent point sees a child's refusal as the same exception.

## On an HTTP Call

Not every outbound call deserves a control point. `Http::breaker()` puts the same circuit on a request from Laravel's HTTP client:

```php
use Illuminate\Support\Facades\Http;

$rates = Http::breaker('rates')->get('https://rates.test/latest')->json();

Http::baseUrl('https://stripe.test')->breaker('stripe', after: 3, within: 60, for: 120)->post('/charges', $payload);
```

`breaker(string $name, ?int $after = null, ?int $within = null, ?int $for = null)` exists on the facade and on a `PendingRequest`; omitted numbers fall back to the configured defaults. It adds a Guzzle middleware that calls `permit()` before sending, throws `Kirschbaum\Monitor\Risks\BreakerOpen` without sending while the circuit is open, counts a connection failure or a 5xx response as a failure, and counts any other response as a success. The circuit is the one control points use, so `Http::breaker('stripe')` and a point with `breaker('stripe')` open and close together.

## The Standalone API

`Monitor::breaker()` returns the `CircuitBreaker` service. It drives the same circuits a point uses.

```php
use Kirschbaum\Monitor\Facades\Monitor;

$breaker = Monitor::breaker();

$breaker->state('stripe');        // a BreakerState: ->state, ->failures, ->openedAt, ->openFor
$breaker->isOpen('stripe');       // true while refusing; false when closed or when a probe is due
$breaker->isClosed('stripe');     // the opposite
$breaker->retryAfter('stripe');   // seconds until the open period ends, 0 when not open

$breaker->permit('stripe');       // a Decision: ->allowed, ->probe, ->retryAfterSeconds
$breaker->recordFailure('stripe');
$breaker->recordSuccess('stripe');

$breaker->open('stripe', 300);    // open by hand for 300 seconds, e.g. from a health check
$breaker->close('stripe');        // close by hand and clear the failures
```

`permit()` is what the policy calls. It moves an expired open circuit to half-open and hands out its single probe, so call it only when you are about to make the attempt and will report back with `recordFailure()` or `recordSuccess()`. `isOpen()` only looks; it does not consume the probe.

`permit()` and `recordFailure()` accept a `Kirschbaum\Monitor\Breaker\BreakerConfig` as a second argument to use numbers other than the defaults:

```php
use Kirschbaum\Monitor\Breaker\BreakerConfig;

$breaker->recordFailure('efiling', new BreakerConfig(after: 2, within: 30, for: 600));
```

A hand-rolled guard around code that is not a control point:

```php
$decision = Monitor::breaker()->permit('search');

if (! $decision->allowed) {
    return $this->cachedResults();
}

try {
    $results = $this->search->query($q);
    Monitor::breaker()->recordSuccess('search');

    return $results;
} catch (SearchUnavailable $e) {
    Monitor::breaker()->recordFailure('search');

    throw $e;
}
```

## BreakerState

`state()` and every breaker event hand back a `Kirschbaum\Monitor\Breaker\BreakerState`, a readonly object. It implements `Arrayable` and `JsonSerializable`, so it can be returned from a health endpoint as it is.

| Member | Meaning |
| --- | --- |
| `state` | A `Kirschbaum\Monitor\Breaker\State` case: `Closed`, `Open` or `HalfOpen`. |
| `failures` | Unix timestamps of the failures inside the window, oldest first, at most `after` of them. |
| `openedAt`, `openFor` | When the circuit opened and for how many seconds, or `null` while closed. |
| `isClosed()`, `isOpen()`, `isHalfOpen()` | The state as booleans. |
| `failureCount()` | How many failures are in the window. |
| `retryAfter(int $now)` | Seconds left on the open period at the given time, `0` when not open. |
| `probeDue(int $now)` | Whether the circuit is open and its period has ended, so the next attempt is the probe. |
| `toArray()` | `state`, `failures`, `opened_at`, `open_for`. |

## The Route Middleware

`Kirschbaum\Monitor\Http\Middleware\CheckBreakers` refuses a route while any of the named circuits is open. The package registers it under the `monitor.breakers` alias; name the circuits after a colon:

```php
Route::post('/payments', [PaymentController::class, 'store'])->middleware('monitor.breakers:stripe');
Route::middleware('monitor.breakers:stripe,efiling')->group(function () {
    // ...
});
```

`->middleware(CheckBreakers::class.':stripe,efiling')` works the same, as does an alias of your own registered in `bootstrap/app.php`.

While a circuit is open the response is an empty `503 Service Unavailable` with three headers:

```
Retry-After: 45
X-Circuit-Breaker: efiling
X-Circuit-Breaker-Status: open
```

`Retry-After` is the real time left on the circuit, at least 1. The middleware uses `isOpen()`, so it never consumes the half-open probe: once the open period ends, the request goes through, and the first control point inside it makes the probe.

## Events

Each transition dispatches an event carrying the circuit name and the new `BreakerState`:

| Event | When |
| --- | --- |
| `Kirschbaum\Monitor\Events\BreakerOpened` | The failure threshold was reached, a probe failed, or `open()` was called. |
| `Kirschbaum\Monitor\Events\BreakerHalfOpen` | The open period ended and the next attempt arrived. |
| `Kirschbaum\Monitor\Events\BreakerClosed` | A probe succeeded or `close()` was called on a circuit that was not already closed. |

Each is also written as a record (`breaker.opened`, `breaker.half_open`, `breaker.closed`); see [Records](records.md). A circuit that was already closed does not announce a close.
