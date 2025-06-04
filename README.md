# Laravel Monitor

![Laravel Supported Versions](https://img.shields.io/badge/laravel-10.x/11.x/12.x-green.svg)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirschbaum-development/monitor.svg?style=flat-square)](https://packagist.org/packages/kirschbaum-development/monitor)
![Application Testing](https://github.com/kirschbaum-development/monitor/actions/workflows/php-tests.yml/badge.svg)
![Static Analysis](https://github.com/kirschbaum-development/monitor/actions/workflows/static-analysis.yml/badge.svg)
![Code Style](https://github.com/kirschbaum-development/monitor/actions/workflows/style-check.yml/badge.svg)

Laravel Monitor is a comprehensive observability toolkit for Laravel applications. It provides structured logging, distributed tracing, performance monitoring, and Critical Control Points (CCP) to help you understand and debug your application's behavior.

## Table of Contents

- [What It Does](#what-it-does)
- [Installation](#installation)
- [Usage](#usage)
  - [Basic Structured Logging](#basic-structured-logging)
  - [Critical Control Points (CCP)](#critical-control-points-ccp)
  - [Distributed Tracing](#distributed-tracing)
  - [Performance Timing](#performance-timing)
- [Configuration](#configuration)
  - [Origin Path Replacers](#origin-path-replacers)
  - [Origin Separators](#origin-separators)
  - [Origin Wrappers](#origin-wrappers)
  - [Exception Tracing](#exception-tracing)
  - [Console Auto-trace](#console-auto-trace)
- [Logging Configuration](#logging-configuration)
- [Environment Variables](#environment-variables)
- [HTTP Middleware](#http-middleware)
  - [StartMonitorTrace Middleware](#startmonitortrace-middleware)
  - [Middleware Registration](#middleware-registration)
  - [Distributed Tracing with Headers](#distributed-tracing-with-headers)
  - [Custom Trace Header](#custom-trace-header)
- [Advanced Usage](#advanced-usage)
  - [Core Usage Patterns](#core-usage-patterns)
  - [Queued Jobs and Async Operations](#queued-jobs-and-async-operations)
  - [Trace-Aware Commands](#trace-aware-commands)
  - [Custom String Origins (Optional)](#custom-string-origins-optional)
- [Output Format](#output-format)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)
- [Security](#security)
- [Sponsorship](#sponsorship)
- [License](#license)

## What It Does

Laravel Monitor provides:

- **Critical Control Points (CCP)** - Monitor operations with automatic start/end logging and exception handling
- **Structured Logging** - Enhanced logging with automatic enrichment (timing, memory, trace IDs)
- **Distributed Tracing** - Correlation IDs that follow requests across operations
- **Timing** - Performance measurements for operations
- **Smart Configuration** - Flexible origin path replacers, wrappers, and exception tracing
- **Console Auto-trace** - Automatic trace initialization for console commands

## Installation

You can install the package via composer:

```bash
composer require kirschbaum-development/monitor
```

Publish the configuration files:

```bash
php artisan vendor:publish --tag="monitor-config"
```

## Usage

### Basic Structured Logging

The core behavior is to pass `$this` for automatic class path resolution:

```php
use Kirschbaum\Monitor\Facades\Monitor;

class UserController extends Controller
{
    public function login()
    {
        // Core usage - automatic class path resolution
        Monitor::from($this)->info('User logged in', ['user_id' => 123]);
        
        // Produces: [App:Http:Controllers:UserController] User logged in
        // With automatic enrichment: trace ID, timing, memory usage
    }
}
```

You can also use string overrides when needed:

```php
// Optional: Override with custom string
Monitor::from('UserService')->info('Custom origin name');
```

### Critical Control Points (CCP)

Monitor operations with automatic start/end logging and exception handling:

```php
use Kirschbaum\Monitor\Facades\Monitor;

class PaymentService
{
    public function processPayment($amount)
    {
        // Monitor a critical operation using $this for automatic resolution
        return Monitor::ccp($this, function () use ($amount) {
            // Your critical code here
            return $this->chargeCard($amount);
        }, ['amount' => $amount, 'currency' => 'USD']);
        
        // Automatically logs:
        // - CCP start: [PaymentService] Starting operation
        // - CCP success: [PaymentService] Operation completed (125ms)
        // - CCP failure: [PaymentService] Operation failed with full exception details
    }
}
```

**Enhanced CCP with Failure Escalation:**

For critical operations that require immediate action on failure, you can provide an `onFail` callback:

```php
use Kirschbaum\Monitor\Facades\Monitor;

class PaymentService
{
    public function processPayment($amount, $userId)
    {
        return Monitor::ccp('payment_processing', function () use ($amount) {
            return $this->chargeCard($amount);
        }, 
        // Context data
        ['amount' => $amount, 'user_id' => $userId, 'currency' => 'USD'], 
        // Failure escalation callback
        function ($exception, $context) {
            // Send immediate alerts to operations team
            NotificationService::alertOps('Critical payment failure', [
                'exception' => $exception->getMessage(),
                'user_id' => $context['user_id'],
                'amount' => $context['amount'],
                'trace_id' => $context['trace_id']
            ]);
            
            // Open circuit breaker to prevent cascade failures
            CircuitBreaker::open('payment_service', '5 minutes');
            
            // Mark service as degraded in health checks
            HealthCheck::markDegraded('payment', 'Payment processing failure');
            
            // Trigger fallback mechanism
            $this->activatePaymentFallback();
        });
    }
}
```

**Real-world Escalation Examples:**

```php
// Database connection CCP with fallback
Monitor::ccp('database_write', function () use ($data) {
    return DB::table('orders')->insert($data);
}, ['table' => 'orders'], function ($exception, $context) {
    // Switch to read replica or cache
    Cache::put("failed_write_{$context['trace_id']}", $data, 3600);
    QueueService::dispatch(new RetryDatabaseWrite($data));
});

// External API CCP with circuit breaker
Monitor::ccp('external_api_call', function () use ($apiData) {
    return $this->thirdPartyService->call($apiData);
}, ['service' => 'third_party'], function ($exception, $context) {
    // Open circuit breaker
    CircuitBreaker::open('third_party_service');
    
    // Use cached response if available
    if ($cached = Cache::get("api_fallback_{$apiData['key']}")) {
        return $cached;
    }
    
    // Alert monitoring systems
    Monitoring::increment('api.third_party.failures');
});

// Critical business process CCP
Monitor::ccp('order_fulfillment', function () use ($order) {
    return $this->fulfillOrder($order);
}, ['order_id' => $order->id], function ($exception, $context) {
    // Immediate executive notification for critical business impact
    NotificationService::alertExecutives('Order fulfillment failure', $context);
    
    // Create incident ticket
    IncidentManagement::createCriticalIncident([
        'title' => 'Order Fulfillment Failure',
        'context' => $context,
        'priority' => 'P1'
    ]);
    
    // Trigger manual review process
    ManualReviewQueue::add($order, 'fulfillment_failure');
});
```

**Callback Safety Features:**

The `onFail` callback is executed safely:
- **Original exception preserved** - The callback doesn't interfere with the primary exception flow
- **Callback exceptions handled** - If the callback itself fails, it's logged separately but doesn't mask the original failure
- **Full context provided** - The callback receives both the exception and complete operation context

```php
Monitor::ccp('safe_callback_example', function () {
    throw new RuntimeException('Primary failure');
}, [], function ($exception, $context) {
    // Even if this callback fails, the original RuntimeException is still thrown
    throw new Exception('Callback failed');
    // This would be logged as FAILURE_CALLBACK_ERROR but won't affect the primary flow
});
```

### Distributed Tracing

```php
use Kirschbaum\Monitor\Facades\Monitor;

class OrderController extends Controller
{
    public function store()
    {
        // Start a trace (typically in middleware or service provider)
        Monitor::trace()->start();
        
        // All subsequent logging will include the same trace ID
        Monitor::from($this)->info('Processing order');
        
        $this->paymentService->charge($amount);
        // PaymentService logs will have the same trace ID
        
        return response()->json(['success' => true]);
    }
}

class PaymentService
{
    public function charge($amount)
    {
        // This will automatically include the trace ID from OrderController
        Monitor::from($this)->info('Charging card', ['amount' => $amount]);
    }
}
```

### Performance Timing

```php
use Kirschbaum\Monitor\Facades\Monitor;

class DataProcessor
{
    public function processLargeDataset()
    {
        // Time operations
        Monitor::time()->start();
        
        // Your processing code here...
        $this->processData();
        
        $elapsed = Monitor::time()->elapsed(); // Milliseconds
        
        Monitor::from($this)->info('Dataset processed', [
            'processing_time_ms' => $elapsed
        ]);
    }
}
```

## Configuration

### Origin Path Replacers

Simplify long class names in logs with cascading replacements:

```php
// config/monitor.php
'origin_path_replacers' => [
    'App\\Http\\Controllers\\Admin\\' => 'Admin\\',
    'App\\Http\\Controllers\\' => 'Web\\',
    'App\\Services\\Payment\\' => 'Payment\\',
    'App\\Jobs\\' => 'Job\\',
],
```

**Examples:**
- `App\Http\Controllers\Admin\UserController` → `Admin:UserController` 
- `App\Http\Controllers\HomeController` → `Web:HomeController`
- `App\Services\Payment\StripeService` → `Payment:StripeService`
- `App\Jobs\SendEmailJob` → `Job:SendEmailJob`

### Origin Separators

The separator controls how namespace segments are converted:

```php
// config/monitor.php
'origin_separator' => ':',
```

**Examples with different separators:**

| Class | Separator | Result |
|-------|-----------|--------|
| `App\Http\Controllers\UserController` | `:` | `App:Http:Controllers:UserController` |
| `App\Http\Controllers\UserController` | `.` | `App.Http.Controllers.UserController` |
| `App\Http\Controllers\UserController` | `-` | `App-Http-Controllers-UserController` |

### Origin Wrappers

The wrapper affects the final visual appearance in logs:

```php
// config/monitor.php
'origin_path_wrapper' => 'square',
```

**All wrapper options:**

| Wrapper | Example Output |
|---------|----------------|
| `'none'` | `UserController message text` |
| `'square'` | `[UserController] message text` |
| `'curly'` | `{UserController} message text` |
| `'round'` | `(UserController) message text` |
| `'angle'` | `<UserController> message text` |
| `'double'` | `"UserController" message text` |
| `'single'` | `'UserController' message text` |
| `'asterisks'` | `*UserController* message text` |

**Combined Example:**
```php
// With these settings:
'origin_path_replacers' => ['App\\Http\\Controllers\\' => 'Web\\'],
'origin_separator' => ':',
'origin_path_wrapper' => 'square',

// Class: App\Http\Controllers\UserController
// Step 1 (replacer): Web\UserController  
// Step 2 (separator): Web:UserController
// Step 3 (wrapper): [Web:UserController]
// Final log: "[Web:UserController] User login successful"
```

### Exception Tracing

Configure how exception stack traces are handled in CCP operations:

```php
// config/monitor.php
'exception_trace' => [
    'enabled' => true,
    'full_on_debug' => true,
    'force_full_trace' => false,
    'max_lines' => 15,
],
```

### Console Auto-trace

Automatically start traces for console commands:

```php
// config/monitor.php
'console_auto_trace' => [
    'enabled' => true,
    'enable_in_testing' => false,
],
```

## Logging Configuration

Configure a dedicated logging channel for Monitor:

```php
// config/logging.php
'channels' => [
    'monitor' => [
        'driver' => 'daily',
        'path' => storage_path('logs/monitor.log'),
        'level' => 'debug',
        'days' => 14,
        'tap' => [
            \Kirschbaum\Monitor\Taps\StructuredLoggingTap::class,
        ],
    ],
],
```

Or merge the provided logging configuration:

```bash
php artisan vendor:publish --tag="monitor-logging"
```

## Environment Variables

```bash
# Enable/disable Monitor functionality
MONITOR_ENABLED=true

# Exception trace configuration
MONITOR_TRACE_ENABLED=true
MONITOR_TRACE_FULL_ON_DEBUG=true
MONITOR_TRACE_FORCE_FULL_TRACE=false
MONITOR_TRACE_MAX_LINES=15

# Console auto-trace
MONITOR_CONSOLE_AUTO_TRACE_ENABLED=true
MONITOR_CONSOLE_AUTO_TRACE_ENABLE_IN_TESTING=false

# HTTP trace header for distributed tracing
MONITOR_TRACE_HEADER=X-Trace-Id
```

## HTTP Middleware

Monitor includes a middleware for automatic HTTP trace management:

### StartMonitorTrace Middleware

The `StartMonitorTrace` middleware automatically:

- **Starts traces** for incoming HTTP requests if none exists
- **Picks up trace IDs** from request headers for distributed tracing
- **Sets trace IDs** in response headers for downstream services
- **Preserves existing traces** when already started

### Middleware Registration

The middleware requires manual registration to give you control over where it runs in the middleware stack:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace::class);
    
    // Or prepend to run it early in the pipeline
    $middleware->prepend(\Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace::class);
})
```

For web-only or API-only registration:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    // Web routes only
    $middleware->web(append: [
        \Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace::class,
    ]);
    
    // API routes only
    $middleware->api(append: [
        \Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace::class,
    ]);
})
```

### Distributed Tracing with Headers

The middleware enables seamless distributed tracing:

```php
// Service A makes request with trace ID
$response = Http::withHeaders([
    'X-Trace-Id' => Monitor::trace()->id()
])->get('https://service-b.example.com/api/data');

// Service B automatically picks up the trace ID
// All logging in Service B will use the same trace ID
Monitor::from($this)->info('Processing request from Service A');
```

### Custom Trace Header

Configure a custom header name:

```php
// config/monitor.php
'trace_header' => 'Custom-Trace-Id',

// Or via environment
MONITOR_TRACE_HEADER=Custom-Trace-Id
```

## Advanced Usage

### Core Usage Patterns

```php
class UserService
{
    public function createUser($userData)
    {
        // Primary pattern - pass $this for automatic resolution
        Monitor::from($this)->info('Creating user', ['email' => $userData['email']]);
        
        $user = User::create($userData);
        
        Monitor::from($this)->info('User created successfully', ['user_id' => $user->id]);
        
        return $user;
    }
}

class ApiController extends Controller
{
    public function __construct(private UserService $userService)
    {
    }
    
    public function store(Request $request)
    {
        // Controller usage with $this
        Monitor::from($this)->info('API request received', [
            'endpoint' => $request->path(),
            'method' => $request->method()
        ]);
        
        $user = $this->userService->createUser($request->validated());
        
        Monitor::from($this)->info('API request completed', ['user_id' => $user->id]);
        
        return response()->json($user);
    }
}
```

### Queued Jobs and Async Operations

When working with queued jobs, trace context doesn't automatically carry over to background workers. Here's how to persist trace information across async operations:

```php
// Job middleware for trace injection
<?php

namespace App\Jobs\Middleware;

use App\Jobs\BaseJob;
use Kirschbaum\Monitor\Facades\Monitor;

class InjectTraceId
{
    public function __construct(private readonly string $traceId) {}

    public function handle(BaseJob $job, callable $next): void
    {
        Monitor::trace()->override($this->traceId);

        $next($job);
    }
}
```

```php
// Base job class that captures and restores trace context
<?php

namespace App\Jobs;

use App\Jobs\Middleware\InjectTraceId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kirschbaum\Monitor\Facades\Monitor;
use LogicException;

abstract class BaseJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public readonly string $traceId;

    public function __construct()
    {
        if (Monitor::trace()->hasNotStarted()) {
            throw new LogicException(static::class.' dispatched without a trace context.');
        }

        $this->traceId = Monitor::trace()->id();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new InjectTraceId($this->traceId),
        ];
    }
}
```

```php
// Example job implementation
<?php

namespace App\Jobs;

use Kirschbaum\Monitor\Facades\Monitor;

class ProcessPaymentJob extends BaseJob
{
    public function __construct(
        private readonly int $userId,
        private readonly float $amount
    ) {
        parent::__construct(); // Captures trace ID
    }

    public function handle(): void
    {
        // This will automatically have the same trace ID as the original request
        Monitor::from($this)->info('Processing payment job started', [
            'user_id' => $this->userId,
            'amount' => $this->amount
        ]);

        // Your payment processing logic here...
        
        Monitor::from($this)->info('Payment job completed successfully');
    }
}
```

**Usage Example:**

```php
class PaymentController extends Controller
{
    public function process(Request $request)
    {
        // Start trace for the request
        Monitor::trace()->start();
        
        Monitor::from($this)->info('Payment request received');
        
        // Dispatch job - trace ID is automatically captured
        ProcessPaymentJob::dispatch($request->user()->id, $request->amount);
        
        Monitor::from($this)->info('Payment job dispatched');
        
        return response()->json(['status' => 'processing']);
    }
}
```

This pattern ensures that:
- **Trace continuity** - All logs from the job will have the same trace ID as the original request
- **Error handling** - Jobs fail fast if dispatched without proper trace context
- **Automatic injection** - The middleware automatically restores trace context when the job runs

### Trace-Aware Commands

For console commands, you can implement a pattern that enforces consistent trace initialization and command logging:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Kirschbaum\Monitor\Facades\Monitor;
use Illuminate\Console\Command;

abstract class TraceAwareCommand extends Command
{
    final public function handle(): int
    {
        // Ensure trace is started for command execution
        if (Monitor::trace()->hasNotStarted()) {
            Monitor::trace()->start();
        }

        Monitor::from($this)->info('Command started', [
            'signature' => $this->signature,
            'arguments' => $this->argument(),
            'options' => $this->options(),
        ]);

        return $this->process();
    }

    /**
     * Your command logic must go here.
     * This enforces the architectural pattern via abstract method.
     */
    abstract protected function process(): int;
}
```

**Example implementation:**

```php
<?php

namespace App\Console\Commands;

use Kirschbaum\Monitor\Facades\Monitor;

class ProcessDataCommand extends TraceAwareCommand
{
    protected $signature = 'data:process {--batch=100 : Number of records to process}';
    protected $description = 'Process data in batches';

    protected function process(): int
    {
        $batchSize = (int) $this->option('batch');
        
        Monitor::from($this)->info('Starting data processing', [
            'batch_size' => $batchSize
        ]);

        try {
            // Your processing logic here
            $this->processDataInBatches($batchSize);
            
            Monitor::from($this)->info('Data processing completed successfully');
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            Monitor::from($this)->error('Data processing failed', [
                'error' => $e->getMessage()
            ]);
            
            return Command::FAILURE;
        }
    }

    private function processDataInBatches(int $batchSize): void
    {
        // Implementation details...
    }
}
```

This architectural pattern provides:
- **Consistent trace initialization** - Every command automatically gets a trace ID
- **Standardized logging** - Command start, arguments, and options are always logged
- **Enforced structure** - The abstract `process()` method ensures developers implement the pattern correctly
- **Clean separation** - Infrastructure concerns (tracing/logging) are separated from business logic

### Custom String Origins (Optional)

When you need to override the automatic class resolution:

```php
// Custom service names
Monitor::from('ExternalAPI')->info('Third-party service called');

// Logical grouping
Monitor::from('CacheWarming')->info('Cache warming started');

// From class constant
Monitor::from(MyService::class)->info('Static reference');
```

## Output Format

Monitor produces structured JSON logs with automatic enrichment:

```json
{
    "level": "info",
    "event": "UserController:info",
    "message": "[UserController] User login successful",
    "trace_id": "9d2b4e8f-3a1c-4d5e-8f2a-1b3c4d5e6f7g",
    "context": {
        "user_id": 123,
        "ip_address": "192.168.1.1"
    },
    "timestamp": "2024-01-15T14:30:45.123Z",
    "duration_ms": 1245.67,
    "memory_mb": 45.23
}
```

## Troubleshooting

**Monitor not logging anything**

Check that `MONITOR_ENABLED=true` in your environment and that your logging channel is properly configured.

**Missing trace IDs**

Ensure you've started a trace with `Monitor::trace()->start()` or enabled console auto-trace.

**Performance impact concerns**

Monitor is designed to be lightweight. In production, consider adjusting log levels and disabling full exception traces.

## Testing

The package includes comprehensive tests:

```bash
vendor/bin/pest
```

Currently includes 126 tests with 333 assertions covering all functionality.

## Security

If you discover any security related issues, please email security@kirschbaumdevelopment.com instead of using the issue tracker.

## Sponsorship

Development of this package is sponsored by Kirschbaum Development Group, a developer driven company focused on problem solving, team building, and community. Learn more [about us](https://kirschbaumdevelopment.com?utm_source=github) or [join us](https://careers.kirschbaumdevelopment.com?utm_source=github)!

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
