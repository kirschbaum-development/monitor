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
    | within Monitor Critical Control Points (CCP). These settings control the
    | verbosity and detail level of exception information captured when CCP
    | operations fail. Note: These settings only apply to CCP exception
    | handling, not general StructuredLogger usage.
    |
    */

    'exception_trace' => [

        /*
        |----------------------------------------------------------------------
        | Exception Trace Enabled
        |----------------------------------------------------------------------
        |
        | Whether to include exception stack traces in CCP failure logs.
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
];
