# Upgrading

- [From 0.1 to 1.0](#from-01-to-10)
- [Platform](#platform)
- [The Facade](#the-facade)
- [Controlled Blocks Become Control Points](#controlled-blocks-become-control-points)
- [The Breaker API](#the-breaker-api)
- [Removed Surfaces](#removed-surfaces)
- [Logging and Middleware](#logging-and-middleware)
- [Configuration Keys](#configuration-keys)
- [Environment Variables](#environment-variables)
- [Exceptions](#exceptions)
- [The Log Shape](#the-log-shape)
- [Checklist](#checklist)

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
| `Monitor::controlled($name, $origin)` | `Monitor::control($name, $origin)`. `$name` may be a string or a backed enum. |
| `Monitor::log($origin)` | Kept; now a PSR-3 logger whose records carry `origin` and `domain` fields. A string origin such as `Monitor::log('Billing')` still works. |
| `Monitor::trace()` | Kept; `start()`, `pickup()`, `override()`, `id()`, `current()`, `hasStarted()`, `clear()`. `hasNotStarted()` is gone. IDs are 32 hex characters; UUIDs are normalised. |
| `Monitor::breaker()` | Kept; see [The Breaker API](#the-breaker-api). |
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

New in 1.0 with no 0.1 equivalent: `->profile()`, `->within()`, `->attempts()`, `->ensure()`, `->policy()`, `->domain()`, `->from()`, `->when()` and `->unless()`, macros, the class form (`ControlPoint`, `#[Point]`), `monitor:points`, `monitor:explain`, `monitor:outcomes`, `monitor:prune`, `make:control-point`, `Monitor::fake()`, the Pest expectations, the PHPStan rule, the outcome store, the MCP server and the Boost guideline and skill.

## The Breaker API

| 0.1 | 1.0 |
| --- | --- |
| `isOpen($name, $threshold, $decay)` | `isOpen($name)`; thresholds come from the policy, a `BreakerConfig`, or `breakers`. |
| `isClosed($name, $threshold, $decay)` | `isClosed($name)`. |
| `isHealthy($name)` | `state($name)->isClosed()`. |
| `recordFailure($name, $decay)` | `recordFailure($name, ?BreakerConfig $config)`. |
| `recordSuccess($name)` | Kept. |
| `forceOpen($name)`, `reset($name)` | `open($name, ?int $forSeconds)`, `close($name)`. |
| `getState($name)` | `state($name)`, returning a `BreakerState` with `state`, `failures`, `openedAt`, `openFor`. |
| `getFailureCount($name)` | `state($name)->failureCount()`. |
| `getLastFailureTime($name)` | The last entry of `state($name)->failures`. |
| | New: `permit($name, ?BreakerConfig $config)` returns a `Decision` and hands out the half-open probe; `retryAfter($name)`. |

## Removed Surfaces

| 0.1 | 1.0 |
| --- | --- |
| `Kirschbaum\Monitor\LogTimer` | Removed. |
| `Kirschbaum\Monitor\Support\LogRedactor`, `RedactorConfig` | Removed. Redaction is the Redactor package's, applied with the profile in `records.redaction`. |
| `Kirschbaum\Monitor\Enums\OriginWrapper` | Removed. The human message keeps a fixed `[Domain:Class]` prefix; origin and domain are fields. |
| `Kirschbaum\Monitor\Support\ControlledContext` | `Kirschbaum\Monitor\ControlStack`, backed by Laravel's Context. |
| `Kirschbaum\Monitor\Data\CircuitBreakerState` | `Kirschbaum\Monitor\Breaker\BreakerState`. |
| The `monitor-logging` publish tag and `config/logging-monitor.php` | Removed. Add `Kirschbaum\Monitor\Logging\JsonTap` to a channel of your own; see [Records](records.md). |

## Logging and Middleware

| 0.1 | 1.0 |
| --- | --- |
| `Kirschbaum\Monitor\Taps\StructuredLoggingTap` | `Kirschbaum\Monitor\Logging\JsonTap` |
| `Kirschbaum\Monitor\Formatters\StructuredJsonFormatter` | `Kirschbaum\Monitor\Logging\RecordFormatter` |
| `Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace` | `Kirschbaum\Monitor\Http\Middleware\StartTrace`, registered under the `monitor.trace` alias; reads `traceparent` first and validates what it gets. |
| `Kirschbaum\Monitor\Http\Middleware\CheckCircuitBreakers` aliased `circuit:a,b` | `Kirschbaum\Monitor\Http\Middleware\CheckBreakers`, registered under the `monitor.breakers` alias: `->middleware('monitor.breakers:a,b')`. |
| | New: `Http::traced()` adds the trace headers to an outgoing request; queued jobs pick up or start a trace. |

## Configuration Keys

| 0.1 | 1.0 |
| --- | --- |
| `enabled` | Removed; it was not read. |
| `prefix`, `origin_path_replacers`, `origin_separator`, `origin_path_wrapper` | `domains.map` and `domains.fallback`, which produce a `domain` field instead of rewriting the origin. |
| `exception_trace.enabled`, `full_on_debug`, `force_full_trace`, `max_lines` | `records.exception_trace` (`never`, `debug`, `always`) and `records.exception_trace_lines`. |
| `console_auto_trace.enabled`, `enable_in_testing` | `trace.console`. The trace now starts on `CommandStarting` rather than when the provider boots. |
| `trace_header` | `trace.legacy_header`; `trace.header` is `traceparent`. |
| `circuit_breaker.default_decay_seconds`, `default_retry_after`, `add_cors_headers` | `breakers.after`, `within`, `for`, `store`, `prefix`. Retry-After is the real time left on the circuit. |
| `redactor.enabled`, `redactor.redactor_profile` | `records.redaction`; empty or `null` disables. |
| | New: `critical_namespaces`, `point_name_pattern`, `discovery`, `inventory.rules`, `profiles`, `records.channel`, `records.levels`, `records.store`, `mcp`. |

## Environment Variables

A `.env` carrying a 0.1 variable that is not listed as kept does nothing in 1.0; nothing reads it.

| 0.1 | 1.0 |
| --- | --- |
| `MONITOR_ENABLED` | Removed. |
| `MONITOR_TRACE_ENABLED`, `MONITOR_TRACE_FULL_ON_DEBUG`, `MONITOR_TRACE_FORCE_FULL_TRACE` | `MONITOR_EXCEPTION_TRACE` (`never`, `debug`, `always`). |
| `MONITOR_TRACE_MAX_LINES` | `records.exception_trace_lines` in the config file; no variable. |
| `MONITOR_CONSOLE_AUTO_TRACE_ENABLED` | `MONITOR_TRACE_CONSOLE`. |
| `MONITOR_CONSOLE_AUTO_TRACE_ENABLE_IN_TESTING` | Removed. |
| `MONITOR_TRACE_HEADER` | Kept, now the legacy header (`trace.legacy_header`). |
| `MONITOR_CIRCUIT_BREAKER_DECAY_SECONDS`, `MONITOR_CIRCUIT_BREAKER_RETRY_AFTER`, `MONITOR_CIRCUIT_BREAKER_CORS_HEADERS` | Removed. `breakers.after`, `within` and `for` are set in the config file; `MONITOR_BREAKER_STORE` names the cache store. |
| `MONITOR_REDACTOR_ENABLED` | Removed. Set `MONITOR_REDACTION_PROFILE` to an empty value to disable. |
| `MONITOR_REDACTOR_PROFILE` | `MONITOR_REDACTION_PROFILE`. |
| | New: `MONITOR_LOG_CHANNEL`, `MONITOR_STORE_ENABLED`, `MONITOR_STORE_CONNECTION`, `MONITOR_MCP_ENABLED`. |

## Exceptions

`Kirschbaum\Monitor\Exceptions\MonitorException` is an interface every exception the package throws implements, so `catch (MonitorException $e)` still catches all of them. `InvalidPointName`, `InvalidProfile`, `InvalidControlPoint` and `InvalidTraceId` extend `InvalidArgumentException`; the risks a run can end with, `Risks\BreakerOpen` and `Risks\EnsureFailed`, extend `RuntimeException` through `Risks\Risk`. Messages quote the offending value in brackets.

## The Log Shape

0.1 wrote one message per log call with the origin in a bracketed prefix and the trace ID, timing and memory inside a `context` blob, and a formatter that read `event` from the wrong place.

1.0 writes one record per transition with a fixed field set at the top level of the log context: `schema` (`monitor/1`), `event`, `point`, `run_id`, `parent_run_id`, `stack`, `trace_id`, `domain`, `origin`, `profile`, `context`, and per event `attempt`, `attempts`, `duration_ms`, `status`, `risk`, `limit`, `exception` and `breaker`. The events are `point.started`, `point.retried`, `point.limit`, `point.recovered`, `point.escalated`, `point.refused`, `point.ended`, `escalation.failed`, `breaker.opened`, `breaker.half_open` and `breaker.closed`; `status` on `point.ended` is `succeeded`, `recovered`, `escalated` or `refused`. The `STARTED` / `ENDED` / `CAUGHT` / `RECOVERED` / `UNCAUGHT` messages are gone.

Dashboards and alerts built on 0.1 messages need rebuilding on the fields. The full field list is in [Records](records.md), and the schema ships in the package at `resources/schema/record-1.json`.

## Checklist

1. Update `composer.json` to PHP 8.3+ and Laravel 12 or 13; Redactor 1.x comes with the package.
2. Re-publish the config with `vendor:publish --tag=monitor-config --force`, or merge the new keys by hand, and delete the 0.1 keys and `config/logging-monitor.php`.
3. Replace every `Monitor::controlled()` chain with `Monitor::control()`: `catching()` becomes one `recover()` per risk, `onUncaughtException()` becomes `escalate()`, the transaction and breaker calls take named arguments, and any handler that returned `null` to mean "handled" now returns the value the caller should get.
4. Check every handler that returned `false`, `0` or `''`: 1.0 treats those as results, not as unhandled.
5. Move critical operations into `App\ControlPoints\{Domain}` classes with `make:control-point`, and give every one an escalation; then run `php artisan monitor:points --check`.
6. Replace `StartMonitorTrace` with `StartTrace` (or the `monitor.trace` alias) and `circuit:` with `monitor.breakers:`; replace `StructuredLoggingTap` with `JsonTap` on any channel that should write NDJSON.
7. Replace the breaker calls listed above, and `Monitor::time()` with `Outcome::$durationMs`.
8. Rewrite dashboards and alerts on the record fields, and, if you want outcomes queryable without a log backend, enable the store and publish and run its migration.
9. Replace the removed environment variables in every `.env`.
10. Run the suite with `Monitor::fake()` in place of any log assertions on the 0.1 messages.
