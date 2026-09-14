# Testing

- [Introduction](#introduction)
- [Monitor::fake()](#monitorfake)
    - [Canned Values and Failures](#canned-values-and-failures)
    - [Reading Outcomes](#reading-outcomes)
- [Assertions](#assertions)
- [Tips](#tips)
- [Pest Expectations](#pest-expectations)
- [The PHPStan Rule](#the-phpstan-rule)

## Introduction

A control point declares what it tolerates. A test of a control point asserts on what it did: it succeeded, it recovered from the declined card, it escalated the timeout, it was refused by the breaker. `Monitor::fake()` gives a test that vocabulary. Two more surfaces prove the declarations themselves: a Pest expectation that a namespace is controlled and complete, and a PHPStan rule that says the same at analysis time.

## Monitor::fake()

```php
use Kirschbaum\Monitor\Facades\Monitor;

$fake = Monitor::fake();
```

The fake replaces the runner and records every `Outcome`. Control points still execute for real: policies run, corrections run, escalations run, records are written. What changes is that every outcome is kept, and a point can be given a canned result.

The fake is also the facade root, so assertions are made through `Monitor::` directly.

### Canned Values and Failures

`returning()` makes a point succeed with a value without running its callback:

```php
Monitor::fake()->returning('payment.charge', ChargeResult::settled());

// or several at once
Monitor::fake([
    'payment.charge' => ChargeResult::settled(),
    'payment.refund' => null,
]);
```

`failing()` makes the point's callback throw, so the point's own corrections, retries, breaker and escalation are exercised as they would be on a real failure:

```php
Monitor::fake()->failing('payment.charge', new CardDeclined('do_not_honor'));

$result = $this->service->charge($invoice);   // goes through recover(CardDeclined::class, ...)

Monitor::assertRecovered('payment.charge', from: CardDeclined::class);
```

Registering a point with one replaces any earlier registration of the other. Points that are registered with neither run their callback.

### Reading Outcomes

```php
Monitor::outcomes();                    // every Outcome, in order
Monitor::outcomes('payment.charge');    // for one point

$fake->forget();                        // discard what was recorded
```

## Assertions

Every assertion returns the fake, so they chain.

| Assertion | Passes when |
| --- | --- |
| `assertRan(string $point, ?Closure $callback = null)` | The point ran at least once; with a callback, at least one `Outcome` satisfies it. |
| `assertRanTimes(string $point, int $times)` | The point ran exactly that many times. |
| `assertNeverRan(string $point)` | The point did not run. |
| `assertSucceeded(string $point, ?Closure $callback = null)` | An outcome of the point is `succeeded`, and satisfies the callback if given. |
| `assertRecovered(string $point, ?string $from = null)` | An outcome is `recovered`; with `$from`, recovered from that exception class. |
| `assertEscalated(string $point, ?string $with = null)` | An outcome is `escalated`; with `$with`, the escaped exception is an instance of that class. |
| `assertRefused(string $point)` | An outcome is `refused` by a breaker. |
| `assertRetried(string $point, ?int $times = null)` | An outcome took more than one attempt; with `$times`, exactly `$times + 1` attempts. |
| `assertLimitBreached(string $point, string $limit)` | An outcome breached the named limit, `duration` or `attempts`. |
| `assertNothingEscalated()` | No recorded outcome escalated. |
| `assertNothingRan()` | Nothing was recorded. |

```php
Monitor::assertRan('payment.charge', fn (Outcome $o): bool => $o->context['invoice'] === 48211)
    ->assertSucceeded('payment.charge')
    ->assertNeverRan('payment.refund')
    ->assertNothingEscalated();
```

Failure messages name the point and what was seen, for instance `Control point [payment.charge] did not end succeeded; saw: escalated.`

## Tips

**Retries sleep.** Fake `Sleep` so a test with backoff runs instantly, and assert on the delays if they matter:

```php
use Illuminate\Support\Sleep;

Sleep::fake();

Monitor::control('search.index')->retry(times: 2, backoffMs: 100)->attempt($flaky);

Sleep::assertSleptTimes(2);
```

**Breakers use the clock.** Circuit state is timestamped through `Carbon::now()`, so `travel()` moves it:

```php
Monitor::breaker()->open('stripe', 60);
$this->travel(61)->seconds();

expect(Monitor::breaker()->isOpen('stripe'))->toBeFalse();
```

**Events.** `Event::fake([...])` with the event classes in [Records](records.md#events) asserts on transitions without reading a log.

**Records.** `timacdonald/log-fake` captures records: after `LogFake::bind()`, `Log::channel()->logs()` holds every line with its level, message and context.

## Pest Expectations

Register once in `tests/Pest.php`:

```php
Kirschbaum\Monitor\Testing\Expectations::register();
```

Two expectations then take a namespace:

```php
expect('App\Services\Payments')->toBeControlled();
expect('App\ControlPoints')->toHaveCompleteControlPoints();
```

`toBeControlled()` passes when every class in the namespace is a control point class or calls `Monitor::control()` inline, with the same exemptions the inventory's `critical_namespace_uncontrolled` rule applies. It reads `discovery.paths`, so the namespace must live under a scanned path.

`toHaveCompleteControlPoints()` passes when no rule reports an error for a class-form point whose origin is in the namespace. Which rules run comes from `inventory.rules`. See [The Inventory](inventory.md#the-rules).

Both are available as static methods for PHPUnit:

```php
use Kirschbaum\Monitor\Testing\Expectations;

Expectations::assertControlled('App\Services\Payments');
Expectations::assertComplete('App\ControlPoints');
```

A failure lists every offending class or point.

## The PHPStan Rule

The same guarantee at analysis time. Include the extension and name the namespaces:

```neon
# phpstan.neon
includes:
    - vendor/kirschbaum-development/monitor/extension.neon

parameters:
    monitor:
        criticalNamespaces:
            - App\ControlPoints
            - App\Services\Payments
```

A class in one of those namespaces that does not extend `ControlPoint`, does not implement `Escalation` or `Policy`, is not abstract, and contains no `Monitor::control()` or `new Control()` call is reported:

```
App\Services\Payments\LegacyCharger sits in a critical namespace but is not a control point and calls none.
💡 Extend Kirschbaum\Monitor\ControlPoint, or wrap the operation in Monitor::control().
```

The error identifier is `monitor.uncontrolled`. The rule reads its namespaces from the PHPStan configuration rather than from `config/monitor.php`, because PHPStan runs without the application; keep the two lists the same.
