# Changelog

All notable changes to this project will be documented in this file.

## v1.0.0 - 2026-09-14

A rewrite around the control point, designed from the problem rather than
from 0.1. There is no compatibility layer; [docs/upgrading.md](docs/upgrading.md)
lists every 0.1 surface and its replacement.

### Added - the control point

- **Two forms.** `Monitor::control('payment.charge', $this)` declares a point
  inline; a class extending `ControlPoint` with a `#[Point]` attribute declares
  one that the inventory can read in full, the stub can generate, and an agent
  can be sent to. Both produce the same `Outcome`.
- **Two terminals.** `run()` returns the value or throws what escaped;
  `attempt()` returns the `Outcome` and never throws for what the operation did.
- **Risks and corrections.** `recover(Class, fn)` declares an expected failure;
  the handler's return value is the result, `null`, `false` and `0` included.
  A handler that throws escalates. First declared match wins.
- **Escalation.** `escalate()` with a closure or an `Escalation` class, called
  for every failure no correction covers; an escalation that throws is recorded
  as `EscalationFailed` and the original exception still propagates.
- **Limits.** `within()` records a duration breach and never fails a completed
  run; `attempts()` caps retries whatever the policies ask; `ensure()` fails a
  run whose result is wrong, after every policy, so it is never retried.
- **Policies.** `Retry` with backoff, multiplier and jitter; `Transaction`
  retrying deadlocks as whole transactions; `Breaker` on a named circuit. A
  fixed pipeline order (breaker, retry, transaction) so a breaker sees one
  failure per run. Custom policies implement one interface.
- **Profiles.** `external`, `database`, `messaging` and `internal` bundle
  policies and limits from config; a point starts from one and overrides what
  it declares.
- **Nesting.** Points nest to any depth with a parent run id and the stack on
  every record. A child's escalation reaches the parent's corrections, a
  child's refusal surfaces as `BreakerOpen`, and retries never compose across
  the stack.
- **Outcome.** One readonly object per run: status, value, exception, the risk
  recovered from, attempts, duration, limits breached, policies, context,
  timeline and stack.

### Added - records

- **One schema for every transition** (`monitor/1`, published at
  `resources/schema/record-1.json`): `point`, `run_id`, `parent_run_id`,
  `trace_id`, `domain`, `origin`, `status`, `attempts`, `duration_ms` and the
  rest as log context fields, with a readable message. Levels per event are
  configurable.
- **Domain as a field.** Derived from the origin's namespace through a
  configurable map, so "escalations per domain" is a query rather than a regex.
- **Redaction through Redactor.** Context and exception messages pass through
  the `observability` profile; identifiers are left alone so records stay
  joinable.
- **Events first.** `PointStarted`, `PointRetried`, `PointLimitBreached`,
  `PointRecovered`, `PointEscalated`, `PointRefused`, `PointEnded`,
  `EscalationFailed`, `BreakerOpened`, `BreakerHalfOpen`, `BreakerClosed`.
  Log lines and the store are listeners.
- **NDJSON.** `JsonTap` and `RecordFormatter` write one JSON object per line
  with the record fields at the top level, and never lose a line to bad bytes.
- **`Monitor::log($origin)`** is a PSR-3 logger bound to an origin, adding
  `origin` and `domain` to every record it writes.

### Added - tracing

- **Laravel Context.** The trace id and the current point live in Context, so
  they reach queued jobs and every log line the application writes. Monitor
  keeps no request state of its own.
- **W3C traceparent** read and written by `StartTrace`, alongside a legacy
  header; incoming values are validated and an invalid one is replaced.
- **`Http::traced()`** adds both headers to an outgoing request.
- **Jobs and console** pick up or start a trace on their own.

### Added - breakers

- **A real state machine**: closed, open, half-open with a single probe, kept
  in the cache so every process sees the same circuit. "after" failures
  "within" seconds open it "for" seconds.
- **Standalone API** through `Monitor::breaker()` and the `CheckBreakers`
  route middleware with a real `Retry-After`.

### Added - store

- **`monitor_outcomes`**, off by default. Rows are written after the response
  or the job, never on the request path; a failing write is logged once and
  never reaches a control point. `monitor:outcomes` and `monitor:prune`.

### Added - verification

- **`Monitor::fake()`** records every outcome while still running the point,
  cans values with `returning()` and failures with `failing()`, and asserts
  with `assertRan`, `assertSucceeded`, `assertRecovered`, `assertEscalated`,
  `assertRefused`, `assertRetried`, `assertLimitBreached`,
  `assertNothingEscalated` and friends.
- **`monitor:points`** inventories every point without running it, class-form
  points in full and inline points by name, with table, JSON and SARIF
  output. `--check` fails the build on duplicate names, names off the pattern,
  a point with no escalation and no catch-all, `control()` that cannot be read
  statically, a class in a critical namespace that is not a control point,
  and warns on catch-alls without escalation and computed names.
- **`monitor:explain`** describes one point's contract in prose.
- **`make:control-point`** generates the class form and a test that already
  uses the fake.
- **A Pest expectation** (`toBeControlled()`, `toHaveCompleteControlPoints()`)
  and **a PHPStan rule** (`monitor.uncontrolled`) with the same semantics.
- **The fake follows the framework's.** `MonitorFake` implements the `Fake`
  contract, `Monitor::fake()` is idempotent, `outcomes()` is a `Collection`,
  and every positive assertion has its negative: `assertNotRan`,
  `assertNotRecovered`, `assertNotEscalated`, `assertNotRefused`,
  `assertNotRetried`, plus `assertRanOnce`. `assertRecovered` and
  `assertEscalated` take a class or a closure.

### Added - agents

- **Guidelines** shipped at `.ai/guidelines/core.blade.php`, which Laravel
  Boost composes into every consuming application's agent guidelines.
- **An MCP server** on `laravel/mcp`: `list_points`, `explain_point`,
  `outcomes` and `escalations` tools, the record schema and guidelines as
  resources, and a `wrap_operation` prompt. Read-only, off by default, every
  response redacted JSON-aware. Registered from config, or by hand in
  `routes/ai.php`.
- **A Boost skill** at `resources/boost/skills/monitor-development/SKILL.md`,
  the path Laravel's own packages use, alongside the guideline.

### Added - conventions, following Laravel's first-party packages

- **Contracts live in `Contracts\`**: `Runner`, `Escalation`, `Limit`,
  `Policy` and `Rule`. Commands live in `Console\Commands\`.
- **Services are open, value objects are `final readonly`.** `Outcome`,
  `RunInfo`, `BreakerState`, `BreakerConfig`, `Decision`, `PointDescription`,
  `Finding`, `ScannedFile`, the limits and `#[Point]` are immutable data;
  everything else can be extended. `Outcome`, `BreakerState`,
  `PointDescription` and `Finding` implement `Arrayable` and `JsonSerializable`.
- **`Control` is `Conditionable` and `Macroable`**, and `StructuredLogger` is
  `Conditionable`, so a declaration can branch with `when()` and `unless()` and
  an application can add methods of its own.
- **Point names accept a backed enum** wherever a name is passed, so an
  application can centralise them.
- **`MonitorException` is an interface.** Each exception keeps its natural SPL
  parent: the four `Invalid*` exceptions extend `InvalidArgumentException`,
  the risks extend `RuntimeException`. Messages quote values in brackets.
- **`CircuitBreaker::attempt()` is `permit()`**, so it no longer shares a name
  with `Control::attempt()` on the same facade. The policies drop their
  `setTimes()` and `setRetries()` setters in favour of constructor arguments.
- **The provider does nothing at boot** beyond registering: commands and
  publishing sit behind `runningInConsole()`, the migration is published with
  `publishesMigrations()` and never auto-loaded, middleware aliases are
  registered when the router resolves, the console trace starts on
  `CommandStarting`, and `php artisan about` shows the package's settings.
- **The store flushes where first-party packages flush**: after each request
  and command through the kernels, after each job, on `Queue::looping` and
  worker stop, and when its buffer reaches 100, so it is safe under Octane and
  in long-running commands.
- **Redaction degrades**: a Redactor failure inside the recorder or the MCP
  server never reaches a control point.

### Changed

- Requires PHP 8.3 to 8.5 and Laravel 12 or 13. Laravel 11 is no longer
  supported.
- Environment variables are `MONITOR_STORE_ENABLED` and `MONITOR_MCP_ENABLED`.
- `kirschbaum-development/redactor` 1.x is a hard dependency.

### Removed

- `Monitor::controlled()`, `catching()`, `onUncaughtException()`,
  `withCircuitBreaker()`, `withDatabaseTransaction()`, `overrideContext()`,
  `addContext()`, `overrideTraceId()` and `NestedControlledBlockException`.
- `Monitor::time()`, `LogTimer`, `Monitor::redactor()`, the internal
  `LogRedactor`, `OriginWrapper` and the origin prefix, separator and wrapper
  settings.
- The `enabled`, `exception_trace`, `console_auto_trace`, `trace_header`,
  `circuit_breaker` and `redactor` config keys.

## v0.1.3 - 2025-07-08

- Switch to scoped container registration.

## v0.1.2 - 2025-07-01

- Fix doubled context on custom taps.

## v0.1.1 - 2025-06-19

- Use the published Redactor package.

## v0.1.0 - 2025-06-17

- First release: controlled blocks, structured logging, trace ids, circuit breaker.
