# The Outcome Store

- [Introduction](#introduction)
- [Enabling It](#enabling-it)
- [The Table](#the-table)
- [When Rows Are Written](#when-rows-are-written)
- [Querying](#querying)
- [monitor:outcomes](#monitoroutcomes)
- [Retention and monitor:prune](#retention-and-monitorprune)

## Introduction

Every run of a control point ends in an `Outcome`, and every outcome is written to the log as a record. The store keeps the same outcomes in a database table, so they can be queried without a log backend: from `monitor:outcomes`, from `monitor:explain` and `monitor:points`, which show a point's recent history and when it last ran, and from the MCP tools an agent uses.

The store is off by default. Records in the log do not depend on it.

## Enabling It

Set `records.store.enabled`, then create the table:

```
MONITOR_STORE_ENABLED=true
```

```bash
php artisan vendor:publish --tag=monitor-migrations
php artisan migrate
```

Publishing copies the migration into `database/migrations` with a timestamp, the way Laravel's first-party packages ship theirs. The package never loads the migration on its own, so the table exists only once you have published and run it.

```php
'records' => [
    'store' => [
        'enabled' => env('MONITOR_STORE_ENABLED', false),
        'connection' => env('MONITOR_STORE_CONNECTION'),
        'table' => 'monitor_outcomes',
        'retention_days' => 30,
    ],
],
```

`connection` is a database connection name, or null for the default. `table` is the table name.

## The Table

| Column | Type | Content |
| --- | --- | --- |
| `id` | bigint | Primary key. |
| `run_id` | string(26), unique | ULID of the run. |
| `parent_run_id` | string(26), nullable, indexed | The enclosing run when nested. |
| `trace_id` | string(32), indexed | The trace. |
| `point` | string, indexed | The control point name. |
| `domain` | string, indexed | The domain. |
| `origin` | string | The fully qualified class. |
| `profile` | string, nullable | The profile the point started from. |
| `status` | string(16), indexed | `succeeded`, `recovered`, `escalated` or `refused`. |
| `recovered_from` | string, nullable | The exception class a correction handled. |
| `exception_class` | string, nullable | The class of the failure, when there was one. |
| `exception_message` | text, nullable | Its message, redacted. |
| `attempts` | unsigned integer | Total attempts. |
| `duration_ms` | decimal(14,3) | Wall time of the run. |
| `stack` | json | Point names, outermost first. |
| `context` | json | The point's context, redacted. |
| `limits_breached` | json | Limit name to threshold and actual. |
| `policies` | json | The policies that ran, described. |
| `timeline` | json | Every transition with its offset in milliseconds. |
| `started_at` | timestamp(3) | When the run started, from the outcome. |
| `ended_at` | timestamp(3), indexed | When the run ended, from the outcome; not when the row was written. |

Context and exception messages go through the same Redactor profile as records, `records.redaction`; see [Records](records.md#redaction).

## When Rows Are Written

Nothing is written on the request path. `Kirschbaum\Monitor\Store\StoreOutcomes` listens to `PointEnded`, keeps the outcome in memory, and writes the buffer in one statement:

- after each HTTP request, once the response has been sent;
- after each console command;
- after each queued job, on `JobProcessed` and `JobExceptionOccurred`, so a long-running worker never holds outcomes across jobs;
- on `Queue::looping` and when a worker stops; and
- as soon as the buffer holds 100 outcomes, so a long-running command that runs many points does not hold them until it exits.

Rows are upserted on `run_id`, so writing the same buffer twice is harmless. A test that wants the rows before the request ends calls `app(StoreOutcomes::class)->flush()`; `pending()` says how many outcomes are waiting.

A failing write is caught. The first failure in a process is logged at warning as `[Monitor] the outcome store could not be written; outcomes are still in the log`; later ones are silent. The store is never the reason a control point fails, and never adds a query to the request that ran it.

## Querying

`Kirschbaum\Monitor\Store\OutcomeStore` is resolved from the container.

```php
use Kirschbaum\Monitor\Store\OutcomeStore;

$store = app(OutcomeStore::class);

$store->enabled();  // bool, from config
```

`recent()` returns rows newest first as arrays with the JSON columns decoded:

```php
$rows = $store->recent([
    'point' => 'payment.charge',      // optional
    'domain' => 'Payments',           // optional
    'status' => 'escalated',          // optional
    'trace' => $traceId,              // optional
    'since' => now()->subHours(2),    // optional DateTimeInterface
], limit: 100);                       // default 50
```

`tally()` counts rows per status, for one point or for all, optionally since a moment:

```php
$store->tally();                                     // ['succeeded' => 412, 'recovered' => 9, 'escalated' => 2]
$store->tally('payment.charge', now()->subDay());    // ['succeeded' => 40, 'recovered' => 1]
```

`lastSeen()` returns, per point, when it last ran and how it ended; `monitor:points` shows this column when the store is on:

```php
$store->lastSeen();
// ['payment.charge' => ['ended_at' => '2026-09-14 18:10:25.011', 'status' => 'succeeded'], ...]
```

`prune()` deletes rows older than the given number of days, or the configured retention, and returns the count.

The rest of the store's public methods:

| Method | Meaning |
| --- | --- |
| `write(array $outcomes)` | Upsert a list of `Outcome` objects and return how many were written. `StoreOutcomes` calls it; a listener of your own may too. |
| `query()` | A query builder on the table, for anything the helpers do not cover. |
| `table()`, `connection()`, `retentionDays()` | The configured table, connection and retention. |

## monitor:outcomes

Lists recent outcomes from the store.

```bash
php artisan monitor:outcomes
php artisan monitor:outcomes --point=payment.charge --since=1h
php artisan monitor:outcomes --domain=Payments --status=escalated --since=7d --limit=200
php artisan monitor:outcomes --trace=7f3a2c1d9e8b4a6f0c5d1e2f3a4b5c6d
php artisan monitor:outcomes --json
```

| Option | Meaning |
| --- | --- |
| `--point=` | Only this control point. |
| `--domain=` | Only this domain. |
| `--status=` | `succeeded`, `recovered`, `escalated` or `refused`. |
| `--trace=` | Only this trace id. |
| `--since=` | How far back: a number followed by `m`, `h` or `d`, e.g. `15m`, `2h`, `7d`. Default `24h`. |
| `--limit=` | Rows to show. Default 50. |
| `--json` | Print the rows as JSON instead of a table. |

The table has the columns Ended, Point, Domain, Status, Attempts, ms, Exception and the first eight characters of the trace id. With `--json` every column comes back, JSON columns decoded, so the output can be piped to `jq`.

Exit codes: 0 on success, including when nothing matched; 1 when the store is disabled; 2 when `--since` is not in the accepted form.

## Retention and monitor:prune

Rows are kept for `records.store.retention_days`, 30 by default. Nothing deletes them unless `monitor:prune` runs:

```bash
php artisan monitor:prune            # older than the configured retention
php artisan monitor:prune --days=7   # older than 7 days
```

Schedule it:

```php
// routes/console.php
Schedule::command('monitor:prune')->daily();
```

The command reports how many rows it deleted, and does nothing while the store is disabled.
