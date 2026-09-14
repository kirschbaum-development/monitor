# Control Points

- [Introduction](#introduction)
- [Names](#names)
- [Origin and Domain](#origin-and-domain)
- [The Inline Form](#the-inline-form)
- [The Class Form](#the-class-form)
    - [The Attribute](#the-attribute)
    - [control()](#control)
    - [context() and handle()](#context-and-handle)
    - [Running a Class](#running-a-class)
    - [Describing a Class](#describing-a-class)
- [Nesting](#nesting)
- [Laravel Context](#laravel-context)

## Introduction

A control point has two forms. The inline form is a fluent declaration at the call site, for an operation that lives in one place. The class form is a file under `App\ControlPoints`, for an operation that is reused, tested on its own, or important enough to be found by name. Both build the same `Kirschbaum\Monitor\Control` and run the same way.

## Names

Every point has a name that appears in code, in records, in the inventory and in tests. It is the join key. Names must match `point_name_pattern`, which by default is dotted lowercase with at least one dot:

```
payment.charge
court.e_filing.submit_v2
```

`Monitor::control('Payment')` throws `InvalidPointName` at declaration time. The inventory's `name_pattern` rule reports class-form points whose attribute name does not match. Change the pattern in `point_name_pattern` if your house style differs.

## Origin and Domain

The origin is the class the point belongs to. Pass `$this` or a class name as the second argument to `Monitor::control()`, or call `from()`; a class-form point's origin is the class itself. When nothing is given the origin is `Kirschbaum\Monitor\Control`.

The domain is derived from the origin's namespace through `domains.map`. Each key is a namespace prefix; a `null` value means "the namespace segment right after the prefix", a string is used as-is. Prefixes are tried in order and the first match wins; a class matching nothing gets `domains.fallback`.

```php
'domains' => [
    'map' => [
        'App\\ControlPoints\\' => null,   // App\ControlPoints\Payments\ChargeCard  => Payments
        'App\\Services\\' => null,        // App\Services\Filings\Submit           => Filings
        'App\\Billing\\' => 'Payments',   // App\Billing\Invoices\Send             => Payments
        'App\\' => null,                  // App\Http\Controllers\X                => Http
    ],
    'fallback' => 'App',
],
```

A class sitting directly under a prefix has no segment of its own and gets the fallback. Override the derived domain for one point with `domain()`, or with the `domain:` argument of the `#[Point]` attribute.

## The Inline Form

```php
use Kirschbaum\Monitor\Facades\Monitor;

$control = Monitor::control('payment.charge', $this);
```

`Monitor::control(string $name, string|object|null $origin = null)` returns a `Control`. Everything on it is chainable.

| Method | Effect |
| --- | --- |
| `from(string\|object $origin)` | Set the origin class. |
| `domain(string $domain)` | Override the derived domain. |
| `with(array $context)` | Merge context into what is recorded with every transition. Calling it twice merges. |
| `profile(string $name)` | Start from a configured bundle of policies and limits. Throws `InvalidProfile` for an unknown name. |
| `retry(...)`, `transaction(...)`, `breaker(...)`, `policy(Policy $policy)` | Policies; see [Policies and Limits](policies-and-limits.md). |
| `within(...)`, `attempts(...)`, `ensure(...)` | Limits; see [Policies and Limits](policies-and-limits.md#limits). |
| `recover(string $class, Closure $handler)` | Declare a risk and its correction; see [Risks and Corrections](risks-and-corrections.md). |
| `escalate(Closure\|string $escalation)` | Who is told when a failure no correction covers gets out. |
| `run(Closure $callback)` | Execute; return the value or throw what escaped. |
| `attempt(Closure $callback)` | Execute; return the `Outcome`. |
| `describe()` | The static description the inventory uses: name, origin, domain, profile, policies, limits, risk classes, whether there is a catch-all, and the escalation as a class name, `'closure'` or `null`. |

`name()`, `origin()`, `resolvedDomain()`, `context()` and `profileName()` read back what was declared. `resolvedPolicies()`, `resolvedWithin()` and `resolvedAttempts()` return the effective policies and limits after the profile is merged.

## The Class Form

```php
namespace App\ControlPoints\Payments;

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;

#[Point('payment.charge', profile: 'external')]
final class ChargeCard extends ControlPoint
{
    public function __construct(private readonly Invoice $invoice, private readonly Money $amount) {}

    protected function control(Control $control): void
    {
        $control
            ->transaction(retries: 2)
            ->breaker('stripe')
            ->ensure(fn (ChargeResult $r): bool => $r->settled, 'charge must be settled')
            ->recover(CardDeclined::class, fn (CardDeclined $e) => ChargeResult::declined($e->code))
            ->escalate(PagePayments::class);
    }

    public function context(): array
    {
        return ['invoice' => $this->invoice->id, 'amount' => $this->amount->minor()];
    }

    public function handle(StripeClient $stripe): ChargeResult
    {
        return $stripe->charge($this->invoice, $this->amount);
    }
}
```

`php artisan make:control-point Payments/ChargeCard` generates this shape and a test. See [Inventory](inventory.md) for its options.

### The Attribute

`#[Point(string $name, ?string $profile = null, ?string $domain = null)]` carries what must be readable without running anything. A class without it throws `InvalidControlPoint` when run or described.

### control()

`control(Control $control)` declares risks, corrections, policies, limits and escalation on the `Control` the point builds. It runs on every execution, and it also runs when the inventory describes the class, on an instance created **without the constructor**. It must therefore not read constructor arguments or anything else only known at runtime. A `control()` that does is reported by the inventory's `unreadable_control` rule, and the point's other rules cannot be trusted until it is fixed. Put per-run data in `context()` instead.

### context() and handle()

`context()` returns the array recorded with every transition of this run; it may read the constructor arguments. `handle()` does the work. It is called through the container, so its parameters are injected; a class without a public `handle()` throws `InvalidControlPoint`.

### Running a Class

| Call | Returns |
| --- | --- |
| `ChargeCard::run(...$arguments)` | The value, or throws what escaped. Arguments go to the constructor. |
| `ChargeCard::attempt(...$arguments)` | The `Outcome`. |
| `(new ChargeCard(...))->execute()` | The `Outcome`, for an instance you already have. |
| `(new ChargeCard(...))->toControl()` | The `Control` the class builds: the attribute's name, profile and domain, then `control()`, then `with($this->context())`. |
| `ChargeCard::point()` | The `Point` attribute instance. |

### Describing a Class

`ChargeCard::describe()` returns the same array as `Control::describe()` plus `form`, `notes` and `unreadable`, without constructing the class. When `control()` throws on the constructor-less instance, `unreadable` is `true` and `notes` says why.

## Nesting

A control point inside another is the normal case. The child gets a `parentId` equal to the parent's run ID, and both carry the stack of point names from outermost to innermost:

```php
$parent = Monitor::control('order.place')->attempt(function () {
    $child = Monitor::control('payment.charge')->attempt(fn () => ...);
    // $child->parentId === parent run id; $child->stack === ['order.place', 'payment.charge']
});
```

When a child escalates, the exception leaves the child and reaches the parent's corrections like any other, so a parent can `recover(CardDeclined::class, ...)` from a child's failure. The child's own outcome is still `Escalated` and still recorded. When a child is refused by its breaker, the parent sees `Kirschbaum\Monitor\Risks\BreakerOpen` and can recover from it.

Retries never compose across the stack. A child's escalation is marked, and the parent's retry policy will not re-run it: two retries inside two retries is two attempts at the leaf, not nine. The parent still retries its own failures. `attempts()` is always a cap on the point that declares it.

Depth is unbounded. `Monitor::stack()` exposes the current stack: `names()`, `depth()`, `current()`, `currentRunId()` and `isInside()`.

## Laravel Context

Monitor keeps its per-request state in Laravel's `Context`, so it propagates to queued jobs and appears on every log line the application writes, not only Monitor's own records:

| Key | Visibility | Value |
| --- | --- | --- |
| `trace_id` | visible | The current trace ID. |
| `control_point` | visible | The innermost running point's name, removed when the outermost point ends. |
| `monitor.stack` | hidden | The stack of `{point, run_id}` entries. |

Nothing else about a run is held in a singleton, which is what makes nesting, queue propagation and long-running workers work without special handling.
