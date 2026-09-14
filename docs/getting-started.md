# Getting Started

- [Introduction](#introduction)
- [Installation](#installation)
- [Your First Control Point](#your-first-control-point)
- [Two Terminals](#two-terminals)
- [The Outcome](#the-outcome)
- [A Control Point Class](#a-control-point-class)
- [Tracing Requests](#tracing-requests)
- [What Lands in the Log](#what-lands-in-the-log)
- [Next Steps](#next-steps)

## Introduction

A control point is an operation whose failure matters: charging a card, filing a document with an external system, posting a ledger entry, sending the one email a person is waiting for. Monitor lets you declare, next to the operation, what it tolerates and what to do about it, then records every run in one shape.

The package registers a service provider and a facade automatically. Nothing runs until you declare a point.

## Installation

Install the package with Composer:

```bash
composer require kirschbaum-development/monitor
```

Then publish the configuration file:

```bash
php artisan vendor:publish --tag=monitor-config
```

This writes `config/monitor.php`. You can run the package without publishing it; the defaults described in [Configuration](configuration.md) apply.

Monitor requires PHP 8.3, 8.4 or 8.5 and Laravel 12 or 13. It depends on [Kirschbaum Redactor](https://github.com/kirschbaum-development/redactor), which is installed with it and used to redact the context of every record.

## Your First Control Point

Wrap the operation in `Monitor::control()`, name it, and say what it tolerates:

```php
use Kirschbaum\Monitor\Facades\Monitor;

$result = Monitor::control('payment.charge', $this)
    ->with(['invoice' => $invoice->id, 'amount' => $amount])
    ->profile('external')
    ->recover(CardDeclined::class, fn (CardDeclined $e) => ChargeResult::declined($e->code))
    ->escalate(PagePayments::class)
    ->run(fn () => $this->stripe->charge($invoice, $amount));
```

Reading top to bottom: the point is called `payment.charge` and belongs to the class of `$this`; it records the invoice and amount with every transition; it starts from the `external` profile, which adds a retry, a circuit breaker and a duration limit; a declined card is expected and turns into a `ChargeResult`; anything else is escalated to `PagePayments` and then thrown.

Names are dotted lowercase, `domain.operation`, and are validated when the point is declared. See [Control Points](control-points.md#names) for the pattern.

## Two Terminals

`run()` returns the value of the operation or of the correction that handled its failure, and throws whatever escaped:

```php
$charge = Monitor::control('payment.charge')->run(fn () => $stripe->charge($amount));
```

`attempt()` returns the `Outcome` and never throws for what the operation did:

```php
use Kirschbaum\Monitor\Status;

$outcome = Monitor::control('court.filing.submit', $this)
    ->recover(FilingRejected::class, fn ($e) => Filing::rejected($e->reasons))
    ->attempt(fn () => $this->efiling->submit($filing));

match ($outcome->status) {
    Status::Succeeded => $this->notifySubmitted($outcome->value),
    Status::Recovered => $this->notifyRejected($outcome->value),
    Status::Escalated => $this->queueForManualFiling($outcome->exception),
    Status::Refused => $this->queueForLater(),
};
```

Both produce the same `Outcome`, the same events and the same records.

## The Outcome

`Kirschbaum\Monitor\Outcome` is a readonly object with everything about one run:

| Property | Meaning |
| --- | --- |
| `point`, `id`, `parentId` | The point name, this run's ULID, and the enclosing run's ULID when nested. |
| `traceId`, `domain`, `origin`, `profile` | Where the run happened. |
| `status` | A `Status` case: `Succeeded`, `Recovered`, `Escalated` or `Refused`. |
| `value` | The operation's return, or the correction's; `null` when escalated or refused. |
| `exception` | The failure that was recovered from or escalated, or `null`. |
| `recoveredFrom` | The risk class the correction handled, when recovered. |
| `attempts`, `durationMs` | How many attempts were made and how long the whole run took. |
| `limitsBreached` | Limits that were exceeded, keyed by name, each with `threshold` and `actual`. |
| `policies` | The policies that ran, described. |
| `context`, `stack`, `timeline` | The point's context, the stack of point names, and every transition with its offset in milliseconds. |

It has `succeeded()`, `recovered()`, `escalated()`, `refused()`, `hasValue()` and `breachedLimit($name)` helpers, `info()` for the run's `RunInfo`, and `toArray()` for a scalar view with the exception summarised. It implements `Arrayable` and `JsonSerializable`, so it can be returned from a route or queued as it is.

## A Control Point Class

An operation that is reused or important enough to have a file becomes a class:

```bash
php artisan make:control-point Payments/ChargeCard --profile=external
```

This creates `app/ControlPoints/Payments/ChargeCard.php` and a test under `tests/Feature/ControlPoints/Payments/`. The class carries a `#[Point]` attribute with its name, declares its contract in `control()`, and does the work in `handle()`, whose parameters are resolved from the container:

```php
namespace App\ControlPoints\Payments;

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;

#[Point('payments.charge_card', profile: 'external')]
final class ChargeCard extends ControlPoint
{
    public function __construct(private readonly Invoice $invoice, private readonly Money $amount) {}

    protected function control(Control $control): void
    {
        $control
            ->recover(CardDeclined::class, fn (CardDeclined $e) => ChargeResult::declined($e->code))
            ->ensure(fn (ChargeResult $r): bool => $r->settled, 'charge must be settled')
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

$result = ChargeCard::run($invoice, $amount);      // value or throws
$outcome = ChargeCard::attempt($invoice, $amount); // Outcome
```

The class form is what the inventory, the test fake and the agent guidelines are built around. See [Control Points](control-points.md#the-class-form).

## Tracing Requests

Add the middleware so every request has a trace ID that reaches every control point, every queued job and every log line:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Kirschbaum\Monitor\Http\Middleware\StartTrace::class);
})
```

It reads a W3C `traceparent` header or the legacy `X-Trace-Id`, validates it, and writes both back on the response. The same middleware is available to route groups under the `monitor.trace` alias. Console commands get a trace when the process starts. See [Tracing](tracing.md).

## What Lands in the Log

Every transition writes one record to your default log channel, with the context redacted. A recovered run of the point above produces four lines: `point.started`, `point.recovered`, `point.ended` and, if the limit was breached, `point.limit`. Each carries `point`, `domain`, `origin`, `run_id`, `trace_id`, `status` and the rest of the fields in [Records](records.md), so `domain:Payments AND status:escalated` is a query in your log backend rather than a regex.

To write newline-delimited JSON with those fields at the top level, add the tap to a channel:

```php
'monitor' => [
    'driver' => 'daily',
    'path' => storage_path('logs/monitor.log'),
    'tap' => [Kirschbaum\Monitor\Logging\JsonTap::class],
],
```

and point `records.channel` at it.

## Next Steps

- [Control Points](control-points.md) for the full builder and the class form.
- [Risks and Corrections](risks-and-corrections.md) for what `recover()` and `escalate()` do exactly.
- [Policies and Limits](policies-and-limits.md) for retry, transactions, breakers, `within()`, `attempts()` and `ensure()`.
- [Inventory](inventory.md) to list every point and check the declarations in CI.
- [Testing](testing.md) for `Monitor::fake()`.
