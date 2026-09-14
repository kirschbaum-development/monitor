# Extending

- [Introduction](#introduction)
- [A Custom Policy](#a-custom-policy)
- [A Custom Escalation](#a-custom-escalation)
- [Listening to Events](#listening-to-events)
- [A Custom Inventory Rule](#a-custom-inventory-rule)
- [Domain Resolution](#domain-resolution)

## Introduction

Monitor's surface is small on purpose, and each piece of it is a contract you can implement: a policy wraps the attempt, an escalation is told about a failure, an inventory rule judges the declarations, and domains come from a map you own.

## A Custom Policy

A policy is a class implementing `Kirschbaum\Monitor\Policies\Policy`:

```php
interface Policy
{
    public const ORDER_BREAKER = 100;
    public const ORDER_RETRY = 200;
    public const ORDER_TRANSACTION = 300;

    /** @param Closure(): mixed $next */
    public function around(Run $run, Closure $next): mixed;

    public function order(): int;

    /** @return array<string, mixed> */
    public function describe(): array;
}
```

`around()` receives the run and the next step of the pipeline. Call `$next()` to make the attempt and return its value; catch what it throws when the policy is about failures. `order()` places the policy in the pipeline, lowest outermost: the shipped breaker is 100, retry 200 and transaction 300, so a breaker sees one result per run and a transaction is retried whole. `describe()` returns a static description with a `type` key for the inventory and the outcome; it must not depend on anything only known at runtime.

The run offers `attempt()` (the current attempt number), `maxAttempts()` (the `attempts()` limit or `PHP_INT_MAX`), `retried($exception, $backoffMs)` for a policy that makes another attempt, `note($event, $detail)` to add an entry to the outcome's timeline, and `info()` for the `RunInfo`.

A policy that logs how long the attempt took and adds it to the timeline:

```php
namespace App\Monitor;

use Closure;
use Kirschbaum\Monitor\Policies\Policy;
use Kirschbaum\Monitor\Run;

final class Timed implements Policy
{
    public function around(Run $run, Closure $next): mixed
    {
        $start = hrtime(true);

        try {
            return $next();
        } finally {
            $run->note('timed', ['ms' => round((hrtime(true) - $start) / 1e6, 3)]);
        }
    }

    public function order(): int
    {
        return 250; // inside retry, outside the transaction
    }

    public function describe(): array
    {
        return ['type' => 'timed'];
    }
}
```

Attach it with `policy()`:

```php
Monitor::control('search.index', $this)->policy(new Timed)->run(...);
```

Passing one of the shipped policies to `policy()` replaces the one of the same type; a custom policy is keyed by its class, so two different custom policies can coexist. Inside a class-form point the call is the same on the `$control` given to `control()`. The `on()` and `except()` filters the shipped policies share come from the trait `Kirschbaum\Monitor\Policies\Concerns\FiltersExceptions`, which you may use in your own.

A policy that retries must consult `Kirschbaum\Monitor\Support\ChildEscalations::contains($exception)` and not retry when it is true; that is how retries stay per point rather than compounding through a nested stack. See [Policies and Limits](policies-and-limits.md).

## A Custom Escalation

An escalation is a class implementing `Kirschbaum\Monitor\Escalations\Escalation`:

```php
namespace App\Escalations;

use Kirschbaum\Monitor\Escalations\Escalation;
use Kirschbaum\Monitor\Outcome;

final class PagePayments implements Escalation
{
    public function __construct(private readonly Pager $pager) {}

    public function handle(Outcome $outcome): void
    {
        $this->pager->page('payments', [
            'point' => $outcome->point,
            'exception' => $outcome->exception?->getMessage(),
            'trace' => $outcome->traceId,
            'run' => $outcome->id,
        ]);
    }
}
```

It is resolved from the container, so constructor injection works. Name it on the point with `escalate(PagePayments::class)`. It is called after the escalation has been recorded and before the exception propagates; if it throws, an `EscalationFailed` event and an `escalation.failed` record say so, and the original exception still leaves the point. A closure passed to `escalate()` behaves the same way.

## Listening to Events

Every transition is an event before it is a record, so alerting and metrics listen rather than parse. The events and what each carries are listed in [Records](records.md#events).

A listener that counts outcomes per point and status:

```php
namespace App\Listeners;

use Kirschbaum\Monitor\Events\PointEnded;

final class CountOutcomes
{
    public function handle(PointEnded $event): void
    {
        $outcome = $event->outcome;

        Metrics::increment('control_point.runs', [
            'point' => $outcome->point,
            'domain' => $outcome->domain,
            'status' => $outcome->status->value,
        ]);

        Metrics::timing('control_point.duration_ms', $outcome->durationMs, ['point' => $outcome->point]);
    }
}
```

One that pages when a breaker opens:

```php
use Kirschbaum\Monitor\Events\BreakerOpened;

Event::listen(BreakerOpened::class, function (BreakerOpened $event): void {
    Pager::page('platform', "circuit {$event->breaker} opened after {$event->state->failureCount()} failures");
});
```

Listeners run synchronously inside the point's run, so keep them fast or queue the work.

## A Custom Inventory Rule

A rule implements `Kirschbaum\Monitor\Inventory\Rules\Rule`:

```php
interface Rule
{
    public function name(): string;

    /** @return list<Finding> */
    public function check(Inventory $inventory): array;
}
```

`check()` receives the whole inventory: `$inventory->points` (every `PointDescription`), `$inventory->classPoints()`, `$inventory->files` (every scanned file with the classes it declares and the inline calls it makes) and `$inventory->find($name)`. It returns findings, each a `Kirschbaum\Monitor\Inventory\Finding` with the rule name, a level (`Finding::ERROR` or `Finding::WARNING`), a message, and optionally the point, file and line.

A rule that requires every external point to declare a breaker:

```php
namespace App\Monitor\Rules;

use Kirschbaum\Monitor\Inventory\Finding;
use Kirschbaum\Monitor\Inventory\Inventory;
use Kirschbaum\Monitor\Inventory\Rules\Rule;

final class ExternalPointsHaveBreakers implements Rule
{
    public function name(): string
    {
        return 'external_without_breaker';
    }

    public function check(Inventory $inventory): array
    {
        $findings = [];

        foreach ($inventory->classPoints() as $point) {
            if ($point->profile !== 'external') {
                continue;
            }

            $types = array_column($point->policies, 'type');

            if (! in_array('breaker', $types, true)) {
                $findings[] = new Finding($this->name(), Finding::ERROR, sprintf('"%s" is external but has no breaker', $point->name), $point->name, $point->file, $point->line);
            }
        }

        return $findings;
    }
}
```

The shipped commands run the configured rules. To run your own, build the inventory yourself with `Discovery::build()`, which takes the paths to scan (null for the configured ones) and the rules to run (null for the configured ones):

```php
use App\Monitor\Rules\ExternalPointsHaveBreakers;
use Kirschbaum\Monitor\Inventory\Discovery;
use Kirschbaum\Monitor\Inventory\Rules\Rules;

$rules = [...Rules::configured(), new ExternalPointsHaveBreakers];

$inventory = app(Discovery::class)->build(rules: $rules);

if ($inventory->hasErrors()) {
    // ...
}
```

`Rules::configured()` returns the shipped rules that `inventory.rules` leaves on; `Rules::all()` maps every shipped rule name to its class. A test is a natural home for a custom rule: build the inventory in it and assert the findings are empty.

## Domain Resolution

A domain is derived from a class name through `domains.map`, tried in order. A null value takes the namespace segment right after the prefix; a string value is used as it is; nothing matching gives `domains.fallback`.

```php
'domains' => [
    'map' => [
        'App\\ControlPoints\\' => null,      // App\ControlPoints\Payments\ChargeCard -> Payments
        'App\\Billing\\' => 'Payments',      // anything under App\Billing -> Payments
        'Modules\\' => null,                 // Modules\Filings\Submit -> Filings
        'App\\' => null,
    ],
    'fallback' => 'App',
],
```

The same map serves control points, `Monitor::log()` and the inventory, so one edit moves a whole namespace to a new domain. A single point can still override it with `domain()` on the control or `domain:` on its `#[Point]` attribute; see [Control Points](control-points.md).
