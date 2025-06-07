<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Monitor Observability Enabled
    |--------------------------------------------------------------------------
    |
    | This value determines whether the Monitor observability toolkit is enabled
    | for your application. When disabled, all logging and tracing functionality
    | will be bypassed. This is particularly useful for testing environments
    | or when you need to temporarily disable observability features.
    |
    */

    'enabled' => env('MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Origin Prefix
    |--------------------------------------------------------------------------
    |
    | An optional prefix that will be prepended to all resolved origin names
    | in log messages. For example, if you set this to 'MyApp', an origin
    | like 'UserController' will become 'MyApp:UserController'. Set to null
    | to disable prefixing entirely.
    |
    | Type: string|null
    | Example: 'MyApp', 'Service', null
    |
    */

    'prefix' => null,

    /*
    |--------------------------------------------------------------------------
    | Origin Path Replacers
    |--------------------------------------------------------------------------
    |
    | This array allows you to replace specific namespace paths in origin
    | names. This is useful for shortening long class names or standardizing
    | naming conventions in logs. Multiple replacements can be applied to the
    | same string - they are processed in array order using strtr().
    |
    | Examples:
    |   'App\\Http\\Controllers\\Admin\\' => 'Admin\\',
    |   'App\\Http\\Controllers\\' => 'Web\\',
    |   'App\\Jobs\\' => 'Job\\',
    |
    | Type: array<string, string>
    | Key: The namespace path to match (case-sensitive)
    | Value: The replacement string
    |
    */

    'origin_path_replacers' => [
        'App\\' => 'Monitor\\',
    ],

    /*
    |--------------------------------------------------------------------------
    | Origin Separator
    |--------------------------------------------------------------------------
    |
    | The character used to separate segments in origin names. This controls
    | how namespace separators (\) are converted when building the origin path.
    | For example, 'App\User\Controller' with separator ':' becomes 'App:User:Controller'.
    |
    | Note: This is different from the origin wrapper - the separator affects
    | the internal structure, while the wrapper affects the final appearance.
    |
    | Type: string
    | Common values: ':', '.', '-', '_'
    |
    */

    'origin_separator' => ':',

    /*
    |--------------------------------------------------------------------------
    | Origin Path Wrapper
    |--------------------------------------------------------------------------
    |
    | Controls how the final origin name appears in log messages by wrapping it
    | with decorative characters. This affects the visual appearance of origins
    | in your logs and can help with readability and parsing.
    |
    | Note: This is different from the origin separator - the separator controls
    | how segments of class names (namespaces) are split, while the wrapper
    | affects the final appearance (e.g., [Monitor:Foo]).
    |
    | Type: string
    | Available options:
    |   - 'none'     => UserController (no wrapping)
    |   - 'square'   => [UserController] (square brackets)
    |   - 'curly'    => {UserController} (curly braces)
    |   - 'round'    => (UserController) (parentheses)
    |   - 'angle'    => <UserController> (angle brackets)
    |   - 'double'   => "UserController" (double quotes)
    |   - 'single'   => 'UserController' (single quotes)
    |   - 'asterisks' => *UserController* (asterisks)
    |
    */

    'origin_path_wrapper' => 'square',

    /*
    |--------------------------------------------------------------------------
    | Exception Trace Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for how exception stack traces are handled and logged
    | within Monitor Controlled execution blocks. These settings control the
    | verbosity and detail level of exception information captured when Controlled
    | operations fail. Note: These settings only apply to Controlled block exception
    | handling, not general StructuredLogger usage.
    |
    */

    'exception_trace' => [

        /*
        |----------------------------------------------------------------------
        | Exception Trace Enabled
        |----------------------------------------------------------------------
        |
        | Whether to include exception stack traces in Controlled block failure logs.
        | When disabled, only basic exception information (class, message,
        | file, line) will be logged without the stack trace.
        |
        */

        'enabled' => env('MONITOR_TRACE_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Full Trace on Debug
        |----------------------------------------------------------------------
        |
        | When the application is in debug mode (app.debug=true), this setting
        | controls whether to include the complete exception trace or a
        | truncated version based on max_lines. This only applies when
        | debug mode is active.
        |
        */

        'full_on_debug' => env('MONITOR_TRACE_FULL_ON_DEBUG', true),

        /*
        |----------------------------------------------------------------------
        | Force Full Trace
        |----------------------------------------------------------------------
        |
        | When enabled, this forces full exception traces to be logged
        | regardless of the debug mode setting. Use with caution in production
        | as it can generate very verbose logs.
        |
        */

        'force_full_trace' => env('MONITOR_TRACE_FORCE_FULL_TRACE', false),

        /*
        |----------------------------------------------------------------------
        | Maximum Trace Lines
        |----------------------------------------------------------------------
        |
        | The maximum number of lines to include in truncated exception stack
        | traces (when not in debug mode or when full_on_debug is false).
        | Must be greater than 0. Full traces are shown when debug conditions
        | are met, ignoring this limit.
        |
        */

        'max_lines' => env('MONITOR_TRACE_MAX_LINES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Console Auto-Trace Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic trace initialization in console environments.
    | The Monitor service provider can automatically start a trace when the
    | application runs in console mode, ensuring all console commands have
    | observability context.
    |
    */

    'console_auto_trace' => [

        /*
        |----------------------------------------------------------------------
        | Enabled
        |----------------------------------------------------------------------
        |
        | Whether console auto-trace functionality is enabled. When true,
        | the service provider will automatically start a trace in console
        | environments if no trace is already active.
        |
        */

        'enabled' => env('MONITOR_CONSOLE_AUTO_TRACE_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Enable in Testing Environment
        |----------------------------------------------------------------------
        |
        | Whether to enable console auto-trace when running in the testing
        | environment. This is typically disabled to avoid interfering with
        | tests, but can be enabled if you want to test console auto-trace
        | functionality specifically.
        |
        */

        'enable_in_testing' => env('MONITOR_CONSOLE_AUTO_TRACE_ENABLE_IN_TESTING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace Header
    |--------------------------------------------------------------------------
    |
    | The HTTP header used to pass and return the trace ID. This allows
    | downstream and upstream services to correlate logs and metrics.
    | The StartMonitorTrace middleware will look for this header in incoming
    | requests and set it in outgoing responses.
    |
    | Default: 'X-Trace-Id'
    | Environment: MONITOR_TRACE_HEADER
    |
    */

    'trace_header' => env('MONITOR_TRACE_HEADER', 'X-Trace-Id'),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for circuit breaker middleware and behavior.
    |
    */

    'circuit_breaker' => [
        'default_decay_seconds' => env('MONITOR_CIRCUIT_BREAKER_DECAY_SECONDS', 300),
        'default_retry_after' => env('MONITOR_CIRCUIT_BREAKER_RETRY_AFTER', 300),
        'add_cors_headers' => env('MONITOR_CIRCUIT_BREAKER_CORS_HEADERS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Redactor Configuration
    |--------------------------------------------------------------------------
    |
    | Generalized redaction engine for scrubbing logs of sensitive or noisy
    | values that shouldn't be persisted. This includes PII, API tokens,
    | auth headers, large blobs, internal references, etc. Can be used for
    | compliance, debugging hygiene, and reducing noise in critical logs.
    |
    */

    'log_redactor' => [

        /*
        |----------------------------------------------------------------------
        | Redactor Enabled
        |----------------------------------------------------------------------
        |
        | Whether the log redactor is enabled. When disabled, no redaction
        | will be performed on log context data.
        |
        */

        'enabled' => env('MONITOR_LOG_REDACTOR_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Redact Keys
        |----------------------------------------------------------------------
        |
        | Keys that always get redacted (case-insensitive match). If any
        | context key matches these names, the entire value will be replaced
        | with the configured replacement string.
        |
        */

        'redact_keys' => [
            'password',
            'token',
            'secret',
            'api_key',
            'authorization',
            'ssn',
            'credit_card',
            'auth_token',
            'bearer_token',
            'access_token',
            'refresh_token',
            'session_id',
            'private_key',
            'client_secret',
        ],

        /*
        |----------------------------------------------------------------------
        | Regex Patterns
        |----------------------------------------------------------------------
        |
        | Regex-based redaction patterns that run against string values.
        | These patterns will match and redact specific sensitive data formats
        | regardless of the key name.
        |
        */

        'patterns' => [
            'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
            'credit_card' => '/\b(?:\d[ -]*?){13,16}\b/',
            'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
            'phone' => '/\+?\d[\d -]{8,14}\d/',
            'bearer_token' => '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/',
            'api_key' => '/(api|apikey|api_key)\s*[:=]\s*[A-Za-z0-9\-_]{20,}/i',
            'jwt_token' => '/eyJ[A-Za-z0-9\-_]+\.eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_.+\/=]*/',
            'ipv4' => '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/',
            'url_with_auth' => '/https?:\/\/[^:\/\s]+:[^@\/\s]+@[^\s]+/',
        ],

        /*
        |----------------------------------------------------------------------
        | Replacement Value
        |----------------------------------------------------------------------
        |
        | The value to use when replacing sensitive data. This will be used
        | for both key-based and pattern-based redactions.
        |
        */

        'replacement' => env('MONITOR_LOG_REDACTOR_REPLACEMENT', '[REDACTED]'),

        /*
        |----------------------------------------------------------------------
        | Mark Redacted
        |----------------------------------------------------------------------
        |
        | When enabled, adds a '_redacted' flag to the context when any
        | redaction occurs. This helps with debugging and compliance auditing.
        |
        */

        'mark_redacted' => env('MONITOR_LOG_REDACTOR_MARK_REDACTED', true),

        /*
        |----------------------------------------------------------------------
        | Maximum Value Length
        |----------------------------------------------------------------------
        |
        | Maximum length for string values before they are considered "large
        | blobs" and get redacted. Set to null to disable length-based redaction.
        | This helps prevent large payloads from cluttering logs.
        |
        */

        'max_value_length' => env('MONITOR_LOG_REDACTOR_MAX_VALUE_LENGTH', 10000),

        /*
        |----------------------------------------------------------------------
        | Redact Large Objects
        |----------------------------------------------------------------------
        |
        | When enabled, arrays or objects with more than the specified number
        | of items will be redacted. This helps prevent large data structures
        | from overwhelming log storage.
        |
        */

        'redact_large_objects' => env('MONITOR_LOG_REDACTOR_LARGE_OBJECTS', true),

        /*
        |----------------------------------------------------------------------
        | Maximum Object Size
        |----------------------------------------------------------------------
        |
        | Maximum number of items in an array or object before it gets redacted.
        | Only applies when redact_large_objects is enabled.
        |
        */

        'max_object_size' => env('MONITOR_LOG_REDACTOR_MAX_OBJECT_SIZE', 50),
    ],
];
