# Monitor Documentation

Monitor gives a Laravel application **control points**: the operations where a failure matters, declared in code as a contract. A control point says what it is called, which domain it belongs to, which failures it expects and what to return instead, which policies bound it (retry, transaction, circuit breaker), which limits it should stay within, and who is told when something it did not expect gets out. Every run ends in exactly one outcome, succeeded, recovered, escalated or refused, and every transition is written as a record with the same fields.

Because the declaration is data, the rest of the package can read it: `monitor:points` lists every control point in the application and checks the declarations in CI, `Monitor::fake()` asserts on outcomes in tests, an optional store keeps outcomes queryable without a log backend, and an MCP server lets an agent ask the application what its control points are and what happened at them.

## Start Here

If you are new to the package, read the pages in this order:

1. [Getting Started](getting-started.md) installs the package, declares a first control point and shows what it writes to the log.
2. [Control Points](control-points.md) covers the inline and class forms, naming, domains and nesting.
3. [Risks and Corrections](risks-and-corrections.md) and [Policies and Limits](policies-and-limits.md) are the two halves of a point's contract.
4. [Inventory](inventory.md) and [Testing](testing.md) are how the contract is verified.

Everything else can be read as you need it.

## Pages

| Page | What it covers |
| --- | --- |
| [Getting Started](getting-started.md) | Installation, the inline form, `run()` and `attempt()`, the `Outcome`, a first class-form point, the trace middleware, and where the records go. |
| [Control Points](control-points.md) | Names, origin and domain, the full inline builder, the class form, the constraint on `control()`, nesting, and what lands in Laravel's Context. |
| [Risks and Corrections](risks-and-corrections.md) | `recover()` semantics, the catch-all, `escalate()` with a closure or a class, `EscalationFailed`, and the risks Monitor raises itself. |
| [Policies and Limits](policies-and-limits.md) | `Retry`, `Transaction`, `Breaker`, pipeline order, custom policies, the three limits, and the shipped profiles. |
| [Records](records.md) | The record every transition writes, its fields and levels, the JSON schema, the NDJSON tap, redaction, and the events behind it. |
| [Tracing](tracing.md) | The trace ID, `traceparent` and the legacy header, the middleware, outgoing propagation with `Http::traced()`, queued jobs and console. |
| [Breakers](breakers.md) | The circuit state machine, the standalone `Monitor::breaker()` API, and the `CheckBreakers` route middleware. |
| [Store](store.md) | The optional outcomes table, when it is written, `monitor:outcomes`, `monitor:prune`, and retention. |
| [Inventory](inventory.md) | `monitor:points`, the rules `--check` enforces, table, JSON and SARIF output, and `monitor:explain`. |
| [Testing](testing.md) | `Monitor::fake()` and its assertions, the Pest expectations, the PHPStan rule, and the package's own conventions. |
| [Agents](agents.md) | The guidelines shipped for Laravel Boost, the MCP server's tools, resources and prompt, and `make:control-point`. |
| [Configuration](configuration.md) | Every key in `config/monitor.php` with its type, default and environment variable. |
| [Extending](extending.md) | Writing a policy, an escalation, an inventory rule, and listening to the events. |
| [Upgrading](upgrading.md) | Every 0.1 surface and its 1.0 replacement. |

## Conventions

Code samples assume the facade is imported:

```php
use Kirschbaum\Monitor\Facades\Monitor;
```

Configuration paths are written relative to the file, so `records.store.enabled` means `config('monitor.records.store.enabled')`.
