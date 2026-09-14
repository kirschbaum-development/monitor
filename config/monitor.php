<?php

declare(strict_types=1);
use Illuminate\Database\DeadlockException;

return [

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    |
    | How a class name becomes a domain on every record. Keys are namespace
    | prefixes, values are the domain to use. A null value means "the next
    | namespace segment after the prefix", so App\ControlPoints\Payments\ChargeCard
    | becomes "Payments". Prefixes are tried in order; the first match wins.
    | A class matching nothing gets the fallback.
    |
    */

    'domains' => [
        'map' => [
            'App\\ControlPoints\\' => null,
            'App\\Services\\' => null,
            'App\\' => null,
        ],
        'fallback' => 'App',
    ],

    /*
    |--------------------------------------------------------------------------
    | Critical Namespaces
    |--------------------------------------------------------------------------
    |
    | Namespaces whose classes are expected to be control points. The inventory
    | check, the Pest expectation and the PHPStan rule all read this list. A
    | class here that neither extends ControlPoint nor calls Monitor::control()
    | fails the check.
    |
    */

    'critical_namespaces' => [
        'App\\ControlPoints',
    ],

    /*
    |--------------------------------------------------------------------------
    | Point Names
    |--------------------------------------------------------------------------
    |
    | Every control point has a name that appears in code, records, the
    | inventory and tests. This pattern is enforced at runtime and by the
    | inventory check. The default is dotted lowercase: "payment.charge".
    |
    */

    'point_name_pattern' => '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/',

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    |
    | Where the inventory looks for control point classes and for inline
    | Monitor::control() calls. Paths are absolute or relative to base_path().
    |
    */

    'discovery' => [
        'paths' => ['app'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory Rules
    |--------------------------------------------------------------------------
    |
    | What monitor:points --check enforces. Every rule is on unless set to
    | false here. Errors fail the check; warnings are printed.
    |
    |   duplicate_names                 error    two points share a name
    |   name_pattern                    error    a name does not match the pattern
    |   missing_escalation              error    a class-form point has no escalation and no catch-all
    |   catch_all_without_escalation    warning  recover(Throwable::class) with nowhere to escalate
    |   critical_namespace_uncontrolled error    a class in a critical namespace is not a control point
    |   dynamic_name                    warning  an inline point's name is not a string literal
    |   unreadable_control              error    control() cannot be read without the constructor
    |
    */

    'inventory' => [
        'rules' => [
            // 'catch_all_without_escalation' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    |
    | A profile is a named bundle of policies and limits. A point that calls
    | ->profile('external') starts with these and overrides what it declares.
    | Keys: retry, transaction, breaker, within, attempts. Omit a key to leave
    | that policy or limit unset.
    |
    */

    'profiles' => [
        'external' => [
            'retry' => ['times' => 2, 'backoff_ms' => 200, 'multiplier' => 2.0, 'jitter' => true],
            'breaker' => ['after' => 5, 'within' => 60, 'for' => 120],
            'within' => 10,
        ],
        'database' => [
            'transaction' => ['retries' => 2, 'on' => [DeadlockException::class]],
            'within' => 5,
        ],
        'messaging' => [
            'retry' => ['times' => 3, 'backoff_ms' => 500, 'multiplier' => 2.0, 'jitter' => true],
            'within' => 15,
        ],
        'internal' => [
            'within' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace
    |--------------------------------------------------------------------------
    |
    | The trace ID follows a request through control points, queued jobs and
    | every log line. The middleware reads W3C traceparent first, then the
    | legacy header, validates what it finds, and writes both back on the
    | response. In console, a trace starts with the process.
    |
    */

    'trace' => [
        'header' => 'traceparent',
        'legacy_header' => env('MONITOR_TRACE_HEADER', 'X-Trace-Id'),
        'console' => env('MONITOR_TRACE_CONSOLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Breakers
    |--------------------------------------------------------------------------
    |
    | Defaults for circuit breakers that are used without explicit numbers,
    | including from the standalone Monitor::breaker() API and the route
    | middleware. "after" failures "within" seconds open the breaker "for"
    | seconds, after which one probe is let through.
    |
    */

    'breakers' => [
        'store' => env('MONITOR_BREAKER_STORE'),
        'prefix' => 'monitor:breaker:',
        'after' => 5,
        'within' => 60,
        'for' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Records
    |--------------------------------------------------------------------------
    |
    | Every transition of a control point produces one record. Records are
    | written as log lines to the channel below (null for the default channel),
    | with context redacted through the given Redactor profile. Exception
    | traces are never included unless asked for.
    |
    | The store keeps outcomes in a table so they can be queried without a log
    | backend. Writes happen after the response is sent or the job finishes,
    | and a failing store never fails a control point.
    |
    */

    'records' => [
        'channel' => env('MONITOR_LOG_CHANNEL'),
        'redaction' => env('MONITOR_REDACTION_PROFILE', 'observability'),
        'exception_trace' => env('MONITOR_EXCEPTION_TRACE', 'never'), // never | debug | always
        'exception_trace_lines' => 15,

        /*
        |----------------------------------------------------------------------
        | Record Levels
        |----------------------------------------------------------------------
        |
        | The PSR-3 level each event is written at. A missing event is written
        | at "info". Event names contain dots, so set the whole array rather
        | than one nested key.
        |
        */

        'levels' => [
            'point.started' => 'debug',
            'point.retried' => 'notice',
            'point.limit' => 'warning',
            'point.recovered' => 'warning',
            'point.refused' => 'warning',
            'point.escalated' => 'error',
            'point.ended' => 'info',
            'escalation.failed' => 'critical',
            'breaker.opened' => 'error',
            'breaker.half_open' => 'notice',
            'breaker.closed' => 'info',
        ],
        'store' => [
            'enabled' => env('MONITOR_STORE_ENABLED', false),
            'connection' => env('MONITOR_STORE_CONNECTION'),
            'table' => 'monitor_outcomes',
            'retention_days' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | A read-only MCP server that lets an agent list control points, read a
    | point's contract, and query recent outcomes. Requires laravel/mcp. Start
    | it with `php artisan mcp:start monitor`.
    |
    */

    'mcp' => [
        'enabled' => env('MONITOR_MCP_ENABLED', false),
        'handle' => 'monitor',
    ],
];
