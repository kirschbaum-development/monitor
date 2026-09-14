# Risks and Corrections

- [Introduction](#introduction)
- [recover()](#recover)
    - [The Return Value Is the Result](#the-return-value-is-the-result)
    - [Order and Matching](#order-and-matching)
    - [The Handler's Arguments](#the-handlers-arguments)
    - [Throwing From a Correction](#throwing-from-a-correction)
- [The Catch-All](#the-catch-all)
- [escalate()](#escalate)
    - [Escalation Classes](#escalation-classes)
    - [When the Escalation Fails](#when-the-escalation-fails)
- [Risks Monitor Raises](#risks-monitor-raises)

## Introduction

A **risk** is an exception class the operation expects. Its **correction** is a handler whose return value becomes the result of the point. A failure with a declared risk ends the run as `Recovered`; a failure with no matching risk ends it as `Escalated`, calls the escalation, and propagates. There is no third state and no way for a failure to leave quietly.

## recover()

```php
Monitor::control('payment.charge')
    ->recover(CardDeclined::class, fn (CardDeclined $e) => ChargeResult::declined($e->code))
    ->recover(InsufficientFunds::class, fn () => ChargeResult::insufficientFunds())
    ->run(fn () => $stripe->charge($amount));
```

`recover(string $class, Closure $handler)` takes a `Throwable` class name and a handler. A class that is not a `Throwable` throws `InvalidControlPoint` at declaration time.

### The Return Value Is the Result

Whatever the handler returns is what `run()` returns and what `Outcome::$value` holds. There are no sentinels: `null`, `false`, `0` and `''` are all values.

```php
$value = Monitor::control('cache.warm')
    ->recover(CacheUnavailable::class, fn () => null)
    ->run(fn () => $this->warm());

// $value === null; the outcome is Recovered, not Escalated.
```

The outcome's `recoveredFrom` is the risk class that matched, and `exception` is the failure the correction handled.

### Order and Matching

Risks are matched with `instanceof`, in declaration order, and the first match wins. Declare the specific class before the general one:

```php
->recover(CardDeclined::class, ...)      // matched for CardDeclined
->recover(PaymentException::class, ...)  // matched for every other PaymentException
```

Declared the other way round, `PaymentException` would catch the declined card too.

### The Handler's Arguments

The handler receives the exception and a provisional `Outcome`:

```php
->recover(CardDeclined::class, function (CardDeclined $e, Outcome $partial) {
    $partial->point;      // 'payment.charge'
    $partial->context;    // what with() recorded
    $partial->attempts;   // attempts made before this failure
    $partial->durationMs; // time so far

    return ChargeResult::declined($e->code);
})
```

The provisional outcome has `status` `Escalated` and no value, because it describes the failure as it stands before the correction runs. The final outcome the point returns is `Recovered`.

### Throwing From a Correction

A handler that throws escalates with the exception it threw; the original failure is not the one that propagates. To escalate the original, rethrow it:

```php
->recover(CardDeclined::class, function (CardDeclined $e) {
    if ($e->code === 'fraud') {
        throw $e;          // escalates CardDeclined
    }

    return ChargeResult::declined($e->code);
})
```

A correction that throws is noted on the outcome's timeline as `correction.threw`.

## The Catch-All

`recover(Throwable::class, fn () => ...)` declared last is the explicit catch-all: every failure becomes that value and nothing escalates.

```php
->recover(Throwable::class, fn () => $this->cache->get('search.results', []))
```

`Control::hasCatchAll()` reports it, and the inventory flags a class-form point that has one and no escalation with the `catch_all_without_escalation` warning, because a catch-all with nowhere to escalate is usually a risk analysis that has not been done. Keep it when the fallback really is fine for every failure, and say so with an escalation that at least records it.

## escalate()

```php
->escalate(function (Outcome $outcome) {
    Alerts::page('payments', $outcome);
})
```

`escalate(Closure|string $escalation)` names who is told when a failure no correction covers gets out. It is called once, after the outcome is complete and its events have fired, and before the exception propagates. It is not called for recovered or refused runs.

The inventory's `missing_escalation` rule reports a class-form point with no escalation and no catch-all, because the one thing a critical operation must never do is fail without anyone being told.

### Escalation Classes

A class implementing `Kirschbaum\Monitor\Contracts\Escalation` is resolved from the container, so it can take constructor dependencies:

```php
use Kirschbaum\Monitor\Contracts\Escalation;
use Kirschbaum\Monitor\Outcome;

class PagePayments implements Escalation
{
    public function __construct(private readonly Pager $pager) {}

    public function handle(Outcome $outcome): void
    {
        $this->pager->page('payments', $outcome->point, $outcome->exception);
    }
}

->escalate(PagePayments::class)
```

Passing a class name that does not implement `Escalation` throws `InvalidControlPoint` at declaration time. The inventory shows the class name; a closure shows as `closure`.

### When the Escalation Fails

An escalation that throws does not replace the original failure. The original still propagates, and a `Kirschbaum\Monitor\Events\EscalationFailed` event carries the outcome and the escalation's exception, which the recorder writes as an `escalation.failed` record at `critical` level. The pager not firing is the loudest thing the package can say.

## Risks Monitor Raises

Two failures come from the package rather than from the operation. Both extend `Kirschbaum\Monitor\Risks\Risk`, which extends `RuntimeException`, and both go through `recover()` and `escalate()` like anything else.

**`Kirschbaum\Monitor\Risks\BreakerOpen`** is raised when the point's circuit breaker is open and nothing was attempted. The run's status is `Refused`, `run()` throws it, and a parent point sees it as an ordinary exception. A point can recover from its own refusal:

```php
->breaker('stripe')
->recover(BreakerOpen::class, fn (BreakerOpen $e) => ChargeResult::deferred($e->retryAfterSeconds))
```

`$e->breaker` is the circuit name and `$e->retryAfterSeconds` the time left.

**`Kirschbaum\Monitor\Risks\EnsureFailed`** is raised when the operation returned but an `ensure()` check did not hold. `$e->reason` is the reason given to `ensure()` and `$e->value` the value that failed it:

```php
->ensure(fn (ChargeResult $r): bool => $r->settled, 'charge must be settled')
->recover(EnsureFailed::class, fn (EnsureFailed $e) => ChargeResult::unsettled($e->value))
```

See [Policies and Limits](policies-and-limits.md#ensure) for when `ensure()` runs.
