---
name: monitor-development
description: "Use when working with Kirschbaum Monitor, the control point package for Laravel. Trigger whenever the query mentions Monitor by name, control points, critical operations, retries, circuit breakers, escalation, Monitor::control, ControlPoint, #[Point], monitor:points, or testing an operation's failure handling with Monitor::fake() in a Laravel project. Tasks include wrapping an operation as a control point, declaring risks and corrections, picking a profile, adding an escalation, reading records and outcomes, and writing Pest tests for a point. Do not trigger for generic logging, generic exception handling, or queue configuration unrelated to control points."
license: MIT
metadata:
  author: kirschbaum-development
---
# Monitor Control Points

## Documentation

The package documentation lives in `vendor/kirschbaum-development/monitor/docs/`. Read `control-points.md`, `risks-and-corrections.md` and `testing.md` before declaring a point.

This application uses `kirschbaum-development/monitor`. A **control point** is an operation whose failure matters: charging a card, filing with an external system, posting a ledger entry, sending an email a person is waiting for, calling any third-party API. Control points are declared, not improvised.

### When an operation is critical

Wrap it in a control point when a failure would lose money, lose data, break a promise to a user, or leave an external system in a different state from ours. When in doubt, it is critical.

### How to declare one

Create a class under `App\ControlPoints\{Domain}` with `php artisan make:control-point {Domain}/{Name}`. The domain is the business area (Payments, Filings, Notifications); it becomes the `domain` field on every record.

```php
#[Point('payment.charge', profile: 'external')]
final class ChargeCard extends ControlPoint
{
    public function __construct(private readonly Invoice $invoice) {}

    protected function control(Control $control): void
    {
        $control
            ->recover(CardDeclined::class, fn (CardDeclined $e) => ChargeResult::declined($e->code))
            ->ensure(fn (ChargeResult $r): bool => $r->settled, 'charge must be settled')
            ->escalate(PagePayments::class);
    }

    public function context(): array { return ['invoice' => $this->invoice->id]; }

    public function handle(StripeClient $stripe): ChargeResult { return $stripe->charge($this->invoice); }
}

ChargeCard::run($invoice);     // value, or throws what escaped
ChargeCard::attempt($invoice); // Outcome: ->status, ->value, ->exception
```

Use `attempt()` when the caller branches on the outcome and `run()` when it wants the value. Points nest: a child's escalation reaches the parent's `recover()`, and retries never compose across the stack.

Rules that `php artisan monitor:points --check` enforces:

- Names are dotted lowercase: `domain.operation`, unique across the app.
- Every point declares `escalate()` (an `Escalation` class or a closure) unless it recovers from `Throwable` deliberately.
- `control()` must not read constructor arguments; the inventory calls it without them.
- Profiles set sensible defaults: `external` for outbound HTTP, `database` for transactional writes, `messaging` for queues and email, `internal` otherwise. Declare on the point anything that differs.

### Risks and corrections

`recover(SomeException::class, fn ($e, Outcome $partial) => $value)` declares an expected failure and what to return instead. The handler's return value **is** the result, `null` included. To escalate from a handler, throw. Do not use `try/catch` around the operation for expected failures; declare them.

### Limits

`within(seconds)` records a duration breach and never fails a completed run. `attempts(n)` caps retries. `ensure(fn ($result): bool, 'reason')` fails the run with `EnsureFailed` when the result is wrong even though the call "succeeded".

### Testing a point

```php
Monitor::fake();                                  // records every outcome, still runs the code
Monitor::fake()->failing('payment.charge', new CardDeclined('do_not_honor'));
Monitor::fake()->returning('payment.charge', ChargeResult::declined());

Monitor::assertSucceeded('payment.charge');
Monitor::assertRecovered('payment.charge', from: CardDeclined::class);
Monitor::assertEscalated('payment.charge', with: ConnectionException::class);
Monitor::assertRetried('payment.charge', times: 2);
Monitor::assertNotEscalated('payment.charge');
Monitor::assertNothingEscalated();
```

### Reading what happened

`php artisan monitor:points` lists every control point and its contract. `php artisan monitor:explain payment.charge` describes one. `php artisan monitor:outcomes --since=1h --status=escalated` lists recent outcomes when the store is on. Records in the log carry `point`, `domain`, `status`, `run_id` and `trace_id` as fields.
