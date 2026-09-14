# Upgrading

- [From 0.1 to 1.0](#from-01-to-10)
- [Platform](#platform)
- [The Facade](#the-facade)
- [Controlled Blocks Become Control Points](#controlled-blocks-become-control-points)
- [Removed Surfaces](#removed-surfaces)
- [Logging and Middleware](#logging-and-middleware)
- [Configuration Keys](#configuration-keys)
- [The Log Shape](#the-log-shape)

## From 0.1 to 1.0

1.0 is a rewrite. There is no compatibility layer: every 0.1 class, method and configuration key was replaced or removed, and the log shape changed. The tables below list each 0.1 surface and what to use instead. Expect to touch every call site, which for most applications is a small number of critical operations.

## Platform

| 0.1 | 1.0 |
| --- | --- |
| PHP 8.3, 8.4 | PHP 8.3, 8.4, 8.5 |
| Laravel 11, 12 | Laravel 12, 13 |
| `kirschbaum-development/redactor` 0.1 | `kirschbaum-development/redactor` 1.x |

## The Facade

| 0.1 | 1.0 |
| --- | --- |
| `Monitor::controlled($name, $origin)` | `Monitor::control($name, $origin)` |
| `Monitor::log($origin)` | Kept; now a PSR-3 logger whose records carry `origin` and `domain` fields. |
| `Monitor::trace()` | Kept; `start()`, `pickup()`, `override()`, `id()`, `current()`, `hasStarted()`, `clear()`. `hasNotStarted()` is gone. IDs are 32 hex characters; UUIDs are normalised. |
| `Monitor::breaker()` | Kept; see below for the changed methods. |
| `Monitor::time()` | Removed. `Outcome::$durationMs` carries the run's duration. |
| `Monitor::redactor()` | Removed. Use `Kirschbaum\Redactor\Facades\Redactor` directly. |
| | New: `Monitor::stack()`, `Monitor::fake()`. |

## Controlled Blocks Become Control Points

| 0.1 | 1.0 |
| --- | --- |
| `->catching([Class::class => fn ($e, $meta)])` | `->recover(Class::class, fn ($e, Outcome $partial))`, one call per risk. |
| A handler returning `null` meant "handled, no value" and made `run()` return `true`; `false`, `0` and `''` were treated as unhandled. | The handler's return value is the result, whatever it is, `null` included. |
| `->onUncaughtException(fn ($e, $meta))` | `->escalate(fn (Outcome $outcome))` or `->escalate(SomeEscalation::class)`. |
| `->withCircuitBreaker($name, $threshold, $decay)` | `->breaker($name, after: $threshold, within: 60, for: $decay)`. |
| `->withDatabaseTransaction($retries, $only, $exclude)` | `->transaction(retries: $retries, on: $only, except: $exclude)`. Deadlocks only, by default. |
| `->overrideContext([...])`, `->addContext([...])` | `->with([...])`, which merges. |
| `->overrideTraceId($id)` | `Monitor::trace()->override($id)` before the point. |
| `->withStructuredLogger($logger)` | Removed; records are written by the package. |
| `->run($callback)` | Kept. New: `->attempt($callback)` returns the `Outcome` without throwing. |
| `NestedControlledBlockException` | Removed; nesting is supported and recorded. |
| `ControlledFailureMeta` array (`controlled_block`, `controlled_block_id`, `breaker_tripped`, ...) | `Kirschbaum\Monitor\Outcome` (`point`, `id`, `status`, `exception`, `attempts`, `limitsBreached`, ...). |
| `Monitor::breaker()->isOpen($name, $threshold, $decay)` | `isOpen($name)`; thresholds come from the policy or `breakers`. |
| `Monitor::breaker()->recordFailure($name, $decay)` | `recordFailure($name, ?BreakerConfig $config)`. |
| `Monitor::breaker()->forceOpen($name)`, `->reset($name)` | `open($name, ?int $forSeconds)`, `close($name)`. |
| `Monitor::breaker()->getState($name)` | `state($name)` returning a `BreakerState` with `state`, `failures`, `openedAt`, `openFor`. |

New in 1.0 with no 0.1 equivalent: `->profile()`, `->within()`, `->attempts()`, `->ensure()`, `->policy()`, `->domain()`, `->from()`, the class form (`ControlPoint`, `#[Point]`), `monitor:points`, `monitor:explain`, `monitor:outcomes`, `monitor:prune`, `make:control-point`, `Monitor::fake()`, the Pest expectations, the PHPStan rule, the outcome store and the MCP server.

## Removed Surfaces

| 0.1 | 1.0 |
| --- | --- |
| `Kirschbaum\Monitor\LogTimer` | Removed. |
| `Kirschbaum\Monitor\Support\LogRedactor`, `RedactorConfig` | Removed. Redaction is the Redactor package's, applied with the profile in `records.redaction`. |
| `Kirschbaum\Monitor\Enums\OriginWrapper` | Removed. The human message keeps a fixed `[Domain:Class]` prefix; origin and domain are fields. |
| `Kirschbaum\Monitor\Support\ControlledContext` | `Kirschbaum\Monitor\ControlStack`, backed by Laravel's Context. |
| `Kirschbaum\Monitor\Data\CircuitBreakerState` | `Kirschbaum\Monitor\Breaker\BreakerState`. |

## Logging and Middleware

| 0.1 | 1.0 |
| --- | --- |
| `Kirschbaum\Monitor\Taps\StructuredLoggingTap` | `Kirschbaum\Monitor\Logging\JsonTap` |
| `Kirschbaum\Monitor\Formatters\StructuredJsonFormatter` | `Kirschbaum\Monitor\Logging\RecordFormatter` |
| `Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace` | `Kirschbaum\Monitor\Http\Middleware\StartTrace`; reads `traceparent` first, validates what it gets. |
| `Kirschbaum\Monitor\Http\Middleware\CheckCircuitBreakers` aliased `circuit:a,b` | `Kirschbaum\Monitor\Http\Middleware\CheckBreakers`; alias it as you like, e.g. `breakers:a,b`. |
| | New: `Http::traced()` adds the trace headers to an outgoing request; queued jobs pick up or start a trace. |

## Configuration Keys

| 0.1 | 1.0 |
| --- | --- |
| `enabled` | Removed; it was not read. |
| `prefix`, `origin_path_replacers`, `origin_separator`, `origin_path_wrapper` | `domains.map` and `domains.fallback`, which produce a `domain` field instead of rewriting the origin. |
| `exception_trace.enabled`, `full_on_debug`, `force_full_trace`, `max_lines` | `records.exception_trace` (`never`, `debug`, `always`) and `records.exception_trace_lines`. |
| `console_auto_trace.enabled`, `enable_in_testing` | `trace.console`. |
| `trace_header` | `trace.legacy_header`; `trace.header` is `traceparent`. |
| `circuit_breaker.default_decay_seconds`, `default_retry_after`, `add_cors_headers` | `breakers.after`, `within`, `for`, `store`, `prefix`. Retry-After is the real time left on the circuit. |
| `redactor.enabled`, `redactor.redactor_profile` | `records.redaction`; empty or `null` disables. |
| | New: `critical_namespaces`, `point_name_pattern`, `discovery`, `inventory.rules`, `profiles`, `records.channel`, `records.levels`, `records.store`, `mcp`. |

## The Log Shape

0.1 wrote one message per log call with the origin in a bracketed prefix and the trace ID, timing and memory inside a `context` blob, and a formatter that read `event` from the wrong place.

1.0 writes one record per transition with a fixed field set at the top level of the log context: `schema` (`monitor/1`), `event`, `point`, `run_id`, `parent_run_id`, `stack`, `trace_id`, `domain`, `origin`, `profile`, `context`, and per event `attempt`, `attempts`, `duration_ms`, `status`, `risk`, `limit`, `exception` and `breaker`. The events are `point.started`, `point.retried`, `point.limit`, `point.recovered`, `point.escalated`, `point.refused`, `point.ended`, `escalation.failed`, `breaker.opened`, `breaker.half_open` and `breaker.closed`; `status` on `point.ended` is `succeeded`, `recovered`, `escalated` or `refused`. The `STARTED` / `ENDED` / `CAUGHT` / `RECOVERED` / `UNCAUGHT` messages are gone.

Dashboards and alerts built on 0.1 messages need rebuilding on the fields. The full field list is in [Records](records.md), and the schema ships in the package at `resources/schema/record-1.json`.
