# Configuration

- [Introduction](#introduction)
- [domains](#domains)
- [critical_namespaces](#critical_namespaces)
- [point_name_pattern](#point_name_pattern)
- [discovery](#discovery)
- [inventory](#inventory)
- [profiles](#profiles)
- [trace](#trace)
- [breakers](#breakers)
- [records](#records)
    - [records.levels](#recordslevels)
    - [records.store](#recordsstore)
- [mcp](#mcp)
- [Environment Variables](#environment-variables)

## Introduction

All of the package's configuration lives in `config/monitor.php`. Publish it with `php artisan vendor:publish --tag=monitor-config`. Every key has a default, so the package runs unpublished.

## domains

How a class name becomes the `domain` field on every record.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `map` | `array<string, string\|null>` | `App\ControlPoints\`, `App\Services\`, `App\` all `null` | Namespace prefix to domain. `null` takes the namespace segment right after the prefix; a string is used as-is. Tried in order, first match wins. |
| `fallback` | `string` | `'App'` | The domain for a class matching no prefix, or sitting directly under one. |

## critical_namespaces

| Type | Default |
| --- | --- |
| `string[]` | `['App\ControlPoints']` |

Namespaces whose classes are expected to be control points. The inventory's `critical_namespace_uncontrolled` rule, the `toBeControlled()` Pest expectation and the PHPStan rule read this list: a class here that neither extends `ControlPoint` nor calls `Monitor::control()` is reported. Interfaces, traits, enums, abstract classes, escalations and policies are exempt.

## point_name_pattern

| Type | Default |
| --- | --- |
| `string` (regex) | `'/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'` |

Every point name is validated against this at declaration time and by the inventory's `name_pattern` rule. The default is dotted lowercase with at least one dot.

## discovery

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `paths` | `string[]` | `['app']` | Directories the inventory scans for control point classes and inline `Monitor::control()` calls. Absolute, or relative to `base_path()`. |

## inventory

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `rules` | `array<string, bool>` | `[]` | Rules to switch off by setting them to `false`. Every rule is on unless listed. |

The rules are `duplicate_names`, `name_pattern`, `missing_escalation`, `critical_namespace_uncontrolled`, `unreadable_control` (errors) and `catch_all_without_escalation`, `dynamic_name` (warnings). See [Inventory](inventory.md#the-rules).

## profiles

| Type | Default |
| --- | --- |
| `array<string, array>` | `external`, `database`, `messaging`, `internal` |

Named bundles applied with `->profile()` or the attribute's `profile:` argument. Each profile is an array with any of these keys:

| Key | Shape |
| --- | --- |
| `retry` | `['times' => int, 'backoff_ms' => int, 'multiplier' => float, 'jitter' => bool, 'on' => class[], 'except' => class[]]` |
| `transaction` | `['retries' => int, 'on' => class[], 'except' => class[], 'connection' => string\|null, 'backoff_ms' => int]` |
| `breaker` | `['name' => string, 'after' => int, 'within' => int, 'for' => int, 'on' => class[], 'except' => class[]]`; `name` defaults to the point's name |
| `within` | seconds, `int\|float` |
| `attempts` | `int` |

The shipped values are listed in [Policies and Limits](policies-and-limits.md#the-shipped-profiles). Class names in `on` and `except` that are not `Throwable` are ignored.

## trace

| Key | Type | Default | Env | Meaning |
| --- | --- | --- | --- | --- |
| `header` | `string` | `'traceparent'` | | The W3C header the middleware reads first and writes back. |
| `legacy_header` | `string` | `'X-Trace-Id'` | `MONITOR_TRACE_HEADER` | A plain trace-ID header read second and written back, for clients that do not speak `traceparent`. |
| `console` | `bool` | `true` | `MONITOR_TRACE_CONSOLE` | Start a trace when the application boots in the console, so commands and workers have one. |

## breakers

Defaults for circuits used without explicit numbers, including the standalone `Monitor::breaker()` API, the route middleware and profiles that omit a value.

| Key | Type | Default | Env | Meaning |
| --- | --- | --- | --- | --- |
| `store` | `string\|null` | `null` | `MONITOR_BREAKER_STORE` | The cache store holding circuit state; `null` is the default store. |
| `prefix` | `string` | `'monitor:breaker:'` | | Cache key prefix. |
| `after` | `int` | `5` | | Failures that open a circuit. |
| `within` | `int` | `60` | | Seconds a failure stays counted. |
| `for` | `int` | `120` | | Seconds a circuit stays open before a probe is allowed. |

## records

| Key | Type | Default | Env | Meaning |
| --- | --- | --- | --- | --- |
| `channel` | `string\|null` | `null` | `MONITOR_LOG_CHANNEL` | The log channel records are written to; `null` is the default channel. |
| `redaction` | `string\|null` | `'observability'` | `MONITOR_REDACTION_PROFILE` | The Redactor profile applied to record context and exception messages, to stored outcomes, and to MCP responses. Empty or `null` disables redaction. |
| `exception_trace` | `'never'\|'debug'\|'always'` | `'never'` | `MONITOR_EXCEPTION_TRACE` | Whether exception summaries include a stack trace: never, only when `app.debug` is on, or always. |
| `exception_trace_lines` | `int` | `15` | | Trace lines kept when a trace is included; the rest is counted in `trace_truncated`. |

### records.levels

| Type | Default |
| --- | --- |
| `array<string, string>` | see below |

The PSR-3 level for each record event. Events not listed are written at `info`.

| Event | Default |
| --- | --- |
| `point.started` | `debug` |
| `point.retried` | `notice` |
| `point.limit` | `warning` |
| `point.recovered` | `warning` |
| `point.refused` | `warning` |
| `point.escalated` | `error` |
| `point.ended` | `info` |
| `escalation.failed` | `critical` |
| `breaker.opened` | `error` |
| `breaker.half_open` | `notice` |
| `breaker.closed` | `info` |

Event names contain dots, so set the whole array rather than one nested key.

### records.store

| Key | Type | Default | Env | Meaning |
| --- | --- | --- | --- | --- |
| `enabled` | `bool` | `false` | `MONITOR_STORE` | Keep outcomes in a table. When enabled the package loads its migration. |
| `connection` | `string\|null` | `null` | `MONITOR_STORE_CONNECTION` | The database connection for the table; `null` is the default. |
| `table` | `string` | `'monitor_outcomes'` | | The table name. |
| `retention_days` | `int` | `30` | | What `monitor:prune` deletes past. |

## mcp

| Key | Type | Default | Env | Meaning |
| --- | --- | --- | --- | --- |
| `enabled` | `bool` | `false` | `MONITOR_MCP` | Register the read-only MCP server. Requires `laravel/mcp`. |
| `handle` | `string` | `'monitor'` | | The handle for `php artisan mcp:start monitor`. |

## Environment Variables

| Variable | Key |
| --- | --- |
| `MONITOR_TRACE_HEADER` | `trace.legacy_header` |
| `MONITOR_TRACE_CONSOLE` | `trace.console` |
| `MONITOR_BREAKER_STORE` | `breakers.store` |
| `MONITOR_LOG_CHANNEL` | `records.channel` |
| `MONITOR_REDACTION_PROFILE` | `records.redaction` |
| `MONITOR_EXCEPTION_TRACE` | `records.exception_trace` |
| `MONITOR_STORE` | `records.store.enabled` |
| `MONITOR_STORE_CONNECTION` | `records.store.connection` |
| `MONITOR_MCP` | `mcp.enabled` |
