# Policies and Limits

- [Introduction](#introduction)
- [Policies](#policies)
    - [Retry](#retry)
    - [Transaction](#transaction)
    - [Breaker](#breaker)
    - [Pipeline Order](#pipeline-order)
    - [policy()](#policy)
- [Limits](#limits)
    - [within()](#within)
    - [attempts()](#attempts)
    - [ensure()](#ensure)
- [Profiles](#profiles)
    - [The Shipped Profiles](#the-shipped-profiles)
    - [How a Profile Merges](#how-a-profile-merges)

## Introduction

A **policy** is a reusable behaviour wrapped around the attempt: retry it, run it in a transaction, refuse it while a circuit is open. A **limit** is a threshold the run should stay within. Policies decide whether the operation runs again; limits decide what is recorded, and in one case whether a completed run counts as a failure.

Every policy and limit can be described without running, which is what the inventory prints.

## Policies

### Retry

```php
->retry(times: 2, backoffMs: 200, multiplier: 2.0, jitter: true, on: [], except: [])
```

Attempts the operation again after a failure, up to `times` more attempts. The delay before the next attempt is `backoffMs × multiplier^(tries − 1)`, and with `jitter` it is a random value between half of that and all of it. Sleeping goes through Laravel's `Sleep`, so `Sleep::fake()` works in tests.

`on` restricts retries to those exception classes; `except` never retries those. Both match with `instanceof`. An empty `on` means every exception is retried.

Each retry policy counts its own tries. The `attempts()` limit caps the total for the run whatever the policies ask. When retries are exhausted, the exception that ended the last attempt is the one that reaches the corrections, so `recover(DeadlockException::class, ...)` matches what actually happened rather than a wrapper.

Every retry fires `PointRetried` with the attempt that failed and the backoff used, and adds a `retried` entry to the outcome's timeline. The outcome's `attempts` is the total.

The policy object is `Kirschbaum\Monitor\Policies\Retry`: `Retry::times(2)->backoff(200, 2.0, true)->on([...])->except([...])`.

### Transaction

```php
->transaction(retries: 0, on: [DeadlockException::class], except: [], connection: null)
```

Runs the operation inside `DB::transaction()` on the given connection (the default when `null`), and when it fails with a retryable exception, rolls back and runs the whole transaction again. Only `Illuminate\Database\DeadlockException` is retried by default, because most failures inside a transaction are not made better by repeating them.

A transaction's retries are counted like a retry policy's, so `retries: 2` can make three attempts. The policy object is `Kirschbaum\Monitor\Policies\Transaction`: `Transaction::retries(2)->on([...])->except([...])->connection('tenant')->backoff(50)`; `backoff()` is a flat delay with jitter between attempts.

The transaction sits inside any retry policy in the pipeline, so each retry gets a fresh transaction rather than retrying inside one that has already failed.

### Breaker

```php
->breaker('stripe', after: 3, within: 60, for: 120)
```

Refuses to attempt the operation while the named circuit is open. `after` failures inside `within` seconds open it; it stays open `for` seconds, then lets one probe through: a success closes it, a failure opens it again for a full period. Arguments left `null` take the defaults in `breakers`.

The breaker is the outermost policy, so it sees one failure per run, after retries: an operation that was retried and then succeeded is a success. `on` and `except` on the policy object decide which exceptions count as failures, so a declined card need not count against a gateway:

```php
use Kirschbaum\Monitor\Policies\Breaker;

->policy(Breaker::named('stripe')->after(3, within: 60)->for(120)->except([CardDeclined::class]))
```

When the circuit is open the run ends `Refused` with a `BreakerOpen` exception and nothing is attempted; see [Risks and Corrections](risks-and-corrections.md#risks-monitor-raises). The state machine, the shared cache store and the standalone API are in [Breakers](breakers.md).

### Pipeline Order

Policies wrap the attempt outermost first, ordered by `Policy::order()` ascending: `Breaker` is 100, `Retry` 200, `Transaction` 300. Declaration order does not matter. `Control::resolvedPolicies()` returns the effective list in pipeline order.

### policy()

`policy(Policy $policy)` adds any object implementing `Kirschbaum\Monitor\Policies\Policy`. A shipped policy passed this way replaces the one of the same type declared with the sugar method, so `->retry(times: 0)->policy(Retry::times(2))` retries twice. Writing your own policy is in [Extending](extending.md#a-custom-policy).

## Limits

### within()

```php
->within(seconds: 5)
```

The whole run, attempts included, should finish inside this many seconds (a float is accepted). A breach is recorded on the outcome under `limitsBreached['duration']` with the threshold and actual in milliseconds, fires `PointLimitBreached`, and is written as a `point.limit` record at `warning`. It never fails the run: a completed side effect is not undone because it was slow, and PHP cannot pre-empt a running call. A slow success is a success that is out of control, and the record says exactly that.

### attempts()

```php
->attempts(3)
```

A hard cap on attempts for the run, whatever the retry policies ask for. When the cap is what stopped a failing run, the outcome records `limitsBreached['attempts']` with the cap and the attempts made. A successful run at the cap is not a breach.

### ensure()

```php
->ensure(fn (ChargeResult $r): bool => $r->settled, 'charge must be settled')
```

A post-condition on the returned value. It is the only limit that fails a run, because it judges the result rather than the road to it: a call that returned 200 with a failing body becomes a failure with `Kirschbaum\Monitor\Risks\EnsureFailed`, which goes through `recover()` and `escalate()` like any other. Several `ensure()` calls are checked in order and the first to fail wins.

`ensure()` runs after every policy, so a failed check is never retried and never re-runs a completed side effect. The reason defaults to `result did not satisfy ensure()`.

## Profiles

A profile is a named bundle of policies and limits in `profiles`. `->profile('external')` applies it, and the `#[Point(..., profile: 'external')]` attribute does the same for a class. An unknown name throws `InvalidProfile` at declaration time.

### The Shipped Profiles

| Profile | Retry | Transaction | Breaker | within |
| --- | --- | --- | --- | --- |
| `external` | 2 times, 200 ms, ×2, jitter | | after 5 within 60 s, for 120 s, named after the point | 10 s |
| `database` | | 2 retries on `DeadlockException` | | 5 s |
| `messaging` | 3 times, 500 ms, ×2, jitter | | | 15 s |
| `internal` | | | | 2 s |

A profile's breaker takes the point's name unless the profile sets `breaker.name`. Each profile key is optional; omit a key to leave that policy or limit unset. `attempts` is also accepted as a profile key.

### How a Profile Merges

The profile's policies come first, then the point's own declarations. A policy declared on the point replaces the profile's policy of the same type; a limit declared on the point replaces the profile's limit of the same kind. So:

```php
Monitor::control('payment.charge')->profile('external')->retry(times: 1)->within(2)
```

runs with the profile's breaker, a retry of one instead of two, and a two-second limit instead of ten. `describe()` shows the merged result.
