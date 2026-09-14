# Testing

- [Introduction](#introduction)
- [Monitor::fake()](#monitorfake)
    - [Canned Values and Failures](#canned-values-and-failures)
    - [Reading Outcomes](#reading-outcomes)
- [Assertions](#assertions)
- [Tips](#tips)
- [Pest Expectations](#pest-expectations)
- [The PHPStan Rule](#the-phpstan-rule)
- [The Package's Own Tests](#the-packages-own-tests)

## Introduction

A control point declares what it tolerates. A test of a control point asserts on what it did: it succeeded, it recovered from the declined card, it escalated the timeout, it was refused by the breaker. `Monitor::fake()` gives a test that vocabulary. Two more surfaces prove the declarations themselves: a Pest expectation that a namespace is controlled and complete, and a PHPStan rule that says the same at analysis time.

## Monitor::fake()

```php
use Kirschbaum\Monitor\Facades\Monitor;

$fake = Monitor::fake();
```

The fake replaces the runner and records every `Outcome`. Control points still execute for real: policies run, corrections run, escalations run, records are written. What changes is that every outcome is kept, and a point can be given a canned result.

It follows the framework's fakes: `MonitorFake` implements `Illuminate\Support\Testing\Fakes\Fake`, so `Monitor::isFake()` is true afterwards; calling `Monitor::fake()` twice returns the same fake rather than wrapping it; and the fake is the facade root, so assertions are made through `Monitor::` directly.

### Canned Values and Failures

`returning()` makes a point succeed with a value without running its callback:

```php
Monitor::fake()
    ->returning('payment.charge', ChargeResult::settled())
    ->returning('payment.refund', null);
```

`failing()` makes the point's callback throw, so the point's own corrections, retries, breaker and escalation are exercised as they would be on a real failure:

```php
Monitor::fake()->failing('payment.charge', new CardDeclined('do_not_honor'));

$result = $this->service->charge($invoice);   // goes through recover(CardDeclined::class, ...)

Monitor::assertRecovered('payment.charge', from: CardDeclined::class);
```

Registering a point with one replaces any earlier registration of the other. Points that are registered with neither run their callback. Wherever a point name is passed, a backed enum is accepted in place of the string.

### Reading Outcomes

`outcomes()` returns an `Illuminate\Support\Collection` of `Outcome` objects, in the order they ended:

```php
Monitor::outcomes();                                   // every Outcome
Monitor::outcomes('payment.charge');                   // for one point
Monitor::outcomes()->where('status', Status::Escalated)->count();

$fake->forget();                                       // discard what was recorded
```

## Assertions

Every assertion returns the fake, so they chain. `$point` is a string or a backed enum. Where an assertion takes a `Closure`, it receives the `Outcome` and returns a bool.

| Assertion | Passes when |
| --- | --- |
| `assertRan($point, ?Closure $callback = null)` | The point ran at least once; with a callback, at least one `Outcome` satisfies it. |
| `assertNotRan($point)` | The point did not run. |
| `assertRanOnce($point)` | The point ran exactly once. |
| `assertRanTimes($point, int $times)` | The point ran exactly that many times. |
| `assertSucceeded($point, ?Closure $callback = null)` | An outcome of the point is `succeeded`, and satisfies the callback if given. |
| `assertRecovered($point, Closure\|string\|null $from = null)` | An outcome is `recovered`; with a class, recovered from that exception class; with a closure, one that satisfies it. |
| `assertNotRecovered($point, Closure\|string\|null $from = null)` | No outcome of the point is `recovered`, or none from that class, or none satisfying the closure. |
| `assertEscalated($point, Closure\|string\|null $with = null)` | An outcome is `escalated`; with a class, the escaped exception is an instance of it; with a closure, one that satisfies it. |
| `assertNotEscalated($point, Closure\|string\|null $with = null)` | No outcome of the point is `escalated`, or none with that class, or none satisfying the closure. |
| `assertRefused($point)` | An outcome is `refused` by a breaker. |
| `assertNotRefused($point)` | No outcome of the point is `refused`. |
| `assertRetried($point, ?int $times = null)` | An outcome took more than one attempt; with `$times`, exactly `$times + 1` attempts. |
| `assertNotRetried($point)` | No outcome of the point took more than one attempt. |
| `assertLimitBreached($point, string $limit)` | An outcome breached the named limit, `duration` or `attempts`. |
| `assertNothingEscalated()` | No recorded outcome escalated. |
| `assertNothingRan()` | Nothing was recorded. |

```php
Monitor::assertRan('payment.charge', fn (Outcome $o): bool => $o->context['invoice'] === 48211)
    ->assertSucceeded('payment.charge')
    ->assertNotRan('payment.refund')
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

**The store.** Rows are written after the request or job, so a test that reads the table first calls `app(Kirschbaum\Monitor\Store\StoreOutcomes::class)->flush()`; `pending()` says how many outcomes are waiting. See [Store](store.md#when-rows-are-written).

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

A class in one of those namespaces that does not extend `ControlPoint`, does not implement `Contracts\Escalation` or `Contracts\Policy`, is not abstract, and contains no `Monitor::control()` or `new Control()` call is reported:

```
App\Services\Payments\LegacyCharger sits in a critical namespace but is not a control point and calls none.
💡 Extend Kirschbaum\Monitor\ControlPoint, or wrap the operation in Monitor::control().
```

The error identifier is `monitor.uncontrolled`. The rule reads its namespaces from the PHPStan configuration rather than from `config/monitor.php`, because PHPStan runs without the application; keep the two lists the same.

## The Package's Own Tests

If you contribute to the package, the suite is Pest on Orchestra Testbench:

```bash
composer test           # full suite, in parallel
composer test-coverage  # with the 100% coverage floor enforced
composer lint           # Pint, Rector, PHPStan (level 10, no baseline)
composer rector:check   # what Rector would change, without changing it
composer mutate         # mutation testing (Pest); local only, not run in CI
composer preflight      # everything CI runs
```

Coverage needs a driver loaded in the CLI. With [Laravel Herd](https://herd.laravel.com), `herd coverage vendor/bin/pest --coverage --min=100` runs the suite under Xdebug with the same floor CI enforces with pcov; without a driver Pest reports no coverage. The PHPUnit configuration raises the memory limit, which the in-process PHPStan rule test needs.

Conventions worth knowing:

- Tests live under `tests/Feature`, `tests/Unit` and `tests/Performance`, all bound to `Tests\TestCase`, which registers the Redactor, MCP and Monitor providers and calls `Http::preventingStrayRequests()`.
- Exceptions and escalations shared across suites live in `tests/Fixtures`; `failingTimes()` in `tests/Pest.php` builds a callback that fails a given number of times.
- `workbench/app/ControlPoints` holds control points the inventory, MCP and PHPStan tests scan. Several are deliberately wrong, one per rule: a duplicate name, an invalid name, a point with no escalation, a catch-all with no escalation, a `control()` that reads a constructor argument, a class in the critical namespace with no control point, a class whose file name does not match, and an inline point with a computed name. `ChargeCard` is the complete one.
- `tests/Performance` holds relative timing guards that skip themselves under coverage instrumentation through `runningWithCoverage()`.
- The PHPStan rule is tested twice: in-process through PHPStan's `RuleTestCase`, which coverage sees, and by running `vendor/bin/phpstan` against a fixture configuration, which proves the extension file wires up.
- The pre-commit hook runs the same checks as `composer preflight`, and the commit-message hook keeps subjects to one line of at most 72 characters; `composer install` wires both up through `core.hooksPath`.
