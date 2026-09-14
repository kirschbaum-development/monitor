# Kirschbaum Monitor

![Laravel Supported Versions](https://img.shields.io/badge/laravel-12.x%20%7C%2013.x-green.svg)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirschbaum-development/monitor.svg?style=flat-square)](https://packagist.org/packages/kirschbaum-development/monitor)
![Application Testing](https://github.com/kirschbaum-development/monitor/actions/workflows/php-tests.yml/badge.svg)
![Static Analysis](https://github.com/kirschbaum-development/monitor/actions/workflows/static-analysis.yml/badge.svg)
![Code Style](https://github.com/kirschbaum-development/monitor/actions/workflows/style-check.yml/badge.svg)

Monitor gives a Laravel application **control points**: the operations where a failure matters, declared in code as a contract. A control point says what it is called, which domain it belongs to, which failures it expects and what to return instead, which policies bound it, which limits it should stay within, and who is told when something it did not expect gets out. Every run ends in one outcome, succeeded, recovered, escalated or refused, and every transition is written as a record with the same fields.

Because the declaration is data, the rest of the package can read it: `monitor:points` lists every point and checks the declarations in CI, `Monitor::fake()` asserts on outcomes by name, an optional store keeps outcomes queryable, and an MCP server lets an agent ask the application what its control points are and what happened at them. The same convention ships as guidelines for Laravel Boost, so an agent adding a critical operation is told once, by the package.

```php
// A critical operation as a try/catch: no name, no attempt count, no trace, null means declined.
try {
    DB::beginTransaction();
    $charge = $this->stripe->charge($amount);
    DB::commit();
} catch (CardDeclined $e) {
    DB::rollBack();
    Log::warning('card declined: '.$e->getMessage());
    return null;
} catch (\Throwable $e) {
    DB::rollBack();
    Log::error($e);
    throw $e;
}

// The same operation as a control point.
return Monitor::control('payment.charge', $this)
    ->with(['invoice' => $invoice->id, 'amount' => $amount])
    ->profile('external')                                  // retry, breaker and duration limit from config
    ->transaction(retries: 2)
    ->ensure(fn (ChargeResult $r): bool => $r->settled, 'charge must be settled')
    ->recover(CardDeclined::class, fn (CardDeclined $e) => ChargeResult::declined($e->code))
    ->escalate(PagePayments::class)
    ->run(fn () => $this->stripe->charge($amount));
```

The declaration says what the operation tolerates, what it tries again, when it stops calling the gateway, what counts as success, and who is paged. It produces one log record per transition with `point`, `domain`, `status`, `run_id` and `trace_id` as fields.

## Quick Start

```bash
composer require kirschbaum-development/monitor
php artisan vendor:publish --tag=monitor-config
```

Add the trace middleware so every request, and every job it dispatches, shares one trace id:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Kirschbaum\Monitor\Http\Middleware\StartTrace::class);
})
```

Declare a critical operation as a class:

```bash
php artisan make:control-point Payments/ChargeCard --profile=external
```

```php
#[Point('payments.charge_card', profile: 'external')]
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

    public function context(): array
    {
        return ['invoice' => $this->invoice->id];
    }

    public function handle(StripeClient $stripe): ChargeResult
    {
        return $stripe->charge($this->invoice);
    }
}

ChargeCard::run($invoice);      // the value, or throws what escaped
ChargeCard::attempt($invoice);  // an Outcome: ->status, ->value, ->exception, ->attempts, ->durationMs
```

Test it by name:

```php
Monitor::fake()->failing('payments.charge_card', new CardDeclined('insufficient_funds'));

ChargeCard::run($invoice);

Monitor::assertRecovered('payments.charge_card', from: CardDeclined::class);
Monitor::assertNothingEscalated();
```

And prove in CI that every critical operation is declared and complete:

```bash
php artisan monitor:points --check
```

## How It Works

A **control point** has a name (`payment.charge`), a domain derived from its class namespace, and a contract. The contract declares **risks** with their **corrections** (`recover()`: the handler's return value is the result), **policies** (`Retry`, `Transaction`, `Breaker`, composed in a fixed order), **limits** (`within()` records a slow run without failing it, `attempts()` caps retries, `ensure()` fails a run whose result is wrong), and an **escalation** for anything no correction covers.

Every run ends in exactly one **outcome**: succeeded, recovered, escalated, or refused by an open breaker. The outcome is what `attempt()` returns, what the events carry, what the fake records, what the log receives as one record per transition, and what the optional store keeps in a table.

Control points nest. A child carries its parent's run id, a child's escalation reaches the parent's corrections, and retries never compose across the stack. The trace id and the current point ride on Laravel's Context, so they reach queued jobs and every log line the application writes.

An **inventory** reads the codebase without running it: `monitor:points` lists every point with its contract, `--check` fails the build when a point has no escalation, a name is duplicated, or a class in a critical namespace is not a control point. The same rules are available as a Pest expectation and a PHPStan rule.

## What It Covers

- **Critical operations** through `Monitor::control()` inline or `ControlPoint` classes, with typed outcomes.
- **Resilience** through retry with backoff, whole-transaction retry on deadlock, `once()` for idempotent runs, and a closed/open/half-open circuit breaker shared across processes, also usable standalone, on the HTTP client as `Http::breaker()`, and as the `CheckBreakers` route middleware.
- **Queues** through `ChargeCard::dispatch()`, which runs a control point as a job tagged for Horizon and released for the breaker's retry-after when refused, and the `WaitForBreaker` job middleware.
- **Records** as one schema for every transition, redacted through [Redactor](https://github.com/kirschbaum-development/redactor), with a tap that writes NDJSON with the fields at the top level.
- **Tracing** with W3C `traceparent` and a legacy header, `Http::traced()` for outgoing calls, and automatic propagation to queued jobs.
- **A store** of outcomes, written after the response, for `monitor:outcomes` and the MCP tools when there is no log backend.
- **Verification** through `Monitor::fake()` and its assertions, `monitor:points --check` with table, JSON and SARIF output, a Pest expectation and a PHPStan rule.
- **Agents** through a guideline and a skill that Laravel Boost composes into every consuming app, a read-only MCP server (`list_points`, `explain_point`, `outcomes`, `escalations`, a `wrap_operation` prompt), and a `make:control-point` stub whose test already uses the fake.

## Documentation

The full documentation lives in [`docs/`](docs/README.md):

| Page | What it covers |
| --- | --- |
| [Getting Started](docs/getting-started.md) | Installation, the inline form, the Outcome, a first class-form point, the trace middleware. |
| [Control Points](docs/control-points.md) | Naming, domains, the builder, the class form, nesting, Laravel Context. |
| [Risks and Corrections](docs/risks-and-corrections.md) | `recover()` semantics, the catch-all, `escalate()`, the risks Monitor raises. |
| [Policies and Limits](docs/policies-and-limits.md) | Retry, Transaction, Breaker, pipeline order, the three limits, profiles. |
| [Records](docs/records.md) | The record schema, levels, redaction, NDJSON, `Monitor::log()`, events. |
| [Tracing](docs/tracing.md) | Trace ids, the middleware, `Http::traced()`, jobs, console. |
| [Jobs](docs/jobs.md) | Dispatching a point as a job, Horizon tags, `WaitForBreaker`, what a job inherits. |
| [Breakers](docs/breakers.md) | The state machine, the standalone API, the route middleware. |
| [Store](docs/store.md) | Enabling the outcome store, what is written and when, `monitor:outcomes`, pruning. |
| [Inventory](docs/inventory.md) | `monitor:points`, every rule, `--check` in CI, `monitor:explain`, `make:control-point`. |
| [Testing](docs/testing.md) | `Monitor::fake()` and its assertions, the Pest expectations, the PHPStan rule, and the package's own tests. |
| [Agents](docs/agents.md) | The Boost guideline and skill, the MCP server, its tools, resources and prompt. |
| [Configuration](docs/configuration.md) | Every key in `config/monitor.php` with its type, default and environment variable. |
| [Extending](docs/extending.md) | Custom policies, escalations, inventory rules, event listeners. |
| [Upgrading](docs/upgrading.md) | Every 0.1 surface and its 1.0 replacement. |

## Requirements

- PHP 8.3, 8.4 or 8.5
- Laravel 12 or 13
- [kirschbaum-development/redactor](https://github.com/kirschbaum-development/redactor) 1.x, installed automatically
- [laravel/mcp](https://github.com/laravel/mcp) 1.x, only for the MCP server

## Testing

```bash
composer test
composer preflight   # pint, rector, phpstan and pest, as the pre-commit hook runs them
composer setup-hooks # point git at .githooks
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each release.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
