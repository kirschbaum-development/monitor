# Laravel Monitor

![Laravel Supported Versions](https://img.shields.io/badge/laravel-10.x/11.x/12.x-green.svg)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirschbaum-development/monitor.svg?style=flat-square)](https://packagist.org/packages/kirschbaum-development/monitor)
![Application Testing](https://github.com/kirschbaum-development/monitor/actions/workflows/php-tests.yml/badge.svg)
![Static Analysis](https://github.com/kirschbaum-development/monitor/actions/workflows/static-analysis.yml/badge.svg)
![Code Style](https://github.com/kirschbaum-development/monitor/actions/workflows/style-check.yml/badge.svg)

Laravel Monitor is an observability helper / toolkit for Laravel applications.

> This package is active development and its API can change abruptly without any notice. Please reach out if you plan to use it in a production environment.

## Table of Contents

- [Installation](#installation)
- [Components](#components)
  - [Structured Logging](#structured-logging)
  - [Controlled Execution Blocks](#controlled-execution-blocks)
  - [Distributed Tracing](#distributed-tracing)
  - [HTTP Middleware](#http-middleware)
  - [Performance Timing](#performance-timing)
  - [Circuit Breaker Direct Access](#circuit-breaker-direct-access)
  - [Log Redactor Direct Access](#log-redactor-direct-access)
  - [Log Redaction](#log-redaction)
- [Complete API Reference](#complete-api-reference)
- [Configuration](#configuration)
- [Output Examples](#output-examples)
- [Testing](#testing)
- [License](#license)

## Installation

Install via Composer:

```bash
composer require kirschbaum-development/monitor
```

Publish configuration files:

```bash
php artisan vendor:publish --tag="monitor-config"
```

## Components

### Structured Logging

**What it does:** Enhances Laravel's logging with automatic enrichment (trace IDs, timing, memory usage, structured context) and smart origin resolution from class namespaces.

```php
use Kirschbaum\Monitor\Facades\Monitor;

// In App\Http\Controllers\Api\UserController
class UserController extends Controller
{
    public function login(LoginRequest $request)
    {
        // Automatic origin resolution from full namespace
        Monitor::log($this)->info('User login attempt', [
            'email' => $request->email,
            'ip' => $request->ip()
        ]);
    }
}

// In App\Services\Payment\StripePaymentService  
class StripePaymentService
{
    public function processPayment($amount)
    {
        // Origin automatically resolved to clean, readable format
        Monitor::log($this)->info('Processing payment', [
            'amount' => $amount,
            'processor' => 'stripe'
        ]);
    }
}
```

**Note:** While you can override with `Monitor::log('CustomName')`, using `log($this)` is preferred as it automatically provides meaningful, consistent origin tracking from your actual class structure.

**What it logs:**
```json
{
    "level": "info",
    "event": "Monitor:Http:Controllers:Api:UserController:info",
    "message": "[Monitor:Http:Controllers:Api:UserController] User login attempt",
    "trace_id": "9d2b4e8f-3a1c-4d5e-8f2a-1b3c4d5e6f7g",
    "context": {
        "email": "[REDACTED]",
        "ip": "192.168.1.1"
    },
    "timestamp": "2024-01-15T14:30:45.123Z",
    "duration_ms": 245,
    "memory_mb": 45.23
}
```

**Note:** The `event` field uses the raw origin name (after path replacers but before wrapper), while the `message` field uses the wrapped origin name for readability.

**Configuration:** Origin path replacers, separators, and wrappers control how class names appear in logs:

```php
// config/monitor.php
'origin_path_replacers' => [
    'App\\' => 'Monitor\\',                  // Default: Replace App\ with Monitor\
    // 'App\\Http\\Controllers\\' => '',     // Example: Remove controller namespace
    // 'App\\Services\\Payment\\' => 'Pay\\', // Example: Shorten payment services
    // 'App\\Services\\' => 'Svc\\',         // Example: General service shortening
],
'origin_separator' => ':',           // App\Http\Controllers\Api\UserController → Monitor:Http:Controllers:Api:UserController  
'origin_path_wrapper' => 'square',   // Monitor:Http:Controllers:Api:UserController → [Monitor:Http:Controllers:Api:UserController]
```

### Controlled Execution Blocks

**What it does:** Monitors critical operations with automatic start/end logging, exception-specific handling, DB transactions, circuit breakers, and true escalation for uncaught exceptions.

**Note:** The second parameter `$origin` (usually `$this`) is optional and automatically provides origin context to the structured logger used by the controlled block, eliminating the need for a separate `->log()` call.

#### **Factory & Execution**

```php
use Kirschbaum\Monitor\Facades\Monitor;

// Create and execute controlled block
$result = Monitor::controlled('payment_processing', $this)
    ->run(function() {
        return processPayment($data);
    });
```

#### **Context Management**

```php
/*
 * Adds additional context to the structured logger.
 */
Monitor::controlled('payment_processing', $this)
    ->addContext([
        'transaction_id' => 'txn_456',
        'gateway' => 'stripe'
    ]);

/*
 * Will completely replace structured logger context.
 * ⚠️ Not recommended unless you have a good reason to do so.
 */
Monitor::controlled('payment_processing', $this)
    ->overrideContext([
        'user_id' => 123,
        'operation' => 'payment',
        'amount' => 99.99
    ]);
```

#### **Exception Handling**

**Exception-Specific Handlers (`catching`):**
```php
Monitor::controlled('payment_processing', $this)
    ->catching([
        DatabaseException::class => function($exception, $meta) {
            $cachedData = ExampleModel::getCachedData();
            return $cachedData; // Recovery value
        },
        NetworkException::class => function($exception, $meta) {
            $this->exampleRetryLater($meta);
            // No return = just handle, don't recover
        },
        PaymentException::class => function($exception, $meta) {
            $this->exampleNotifyFinanceTeam($exception, $meta);
            throw $exception; // Re-throw if needed
        },
        // Other exception types remain uncaught.
    ])
```

**Uncaught Exception Handling (`onUncaughtException`):**
```php
Monitor::controlled('payment_processing', $this)
    ->onUncaughtException(function($exception, $meta) {
        // Example actions, the exception will remain uncaught
        $this->alertOpsTeam($exception, $meta);
        $this->sendToErrorTracking($exception);
    })
```

**Key Behavior:**
- Only specified exception types in `catching()` are handled
- Handlers can return recovery values to prevent re-throwing
- `onUncaughtException()` **only** fires for exceptions not caught by `catching()` handlers
- True separation between expected (caught) and unexpected (uncaught) failures

#### **Circuit Breaker & Database Protection**

**What are Circuit Breakers?**
Circuit breakers prevent cascading failures by temporarily stopping requests to a failing service, allowing it time to recover. They automatically "open" after a threshold of failures and "close" once the service is healthy again, protecting your application from wasting resources on operations likely to fail.

```php
Monitor::controlled('payment_processing', $this)
    ->withCircuitBreaker('payment_gateway', 3, 60) // 3 failures, 60s timeout
    ->withDatabaseTransaction(2, [DeadlockException::class], [ValidationException::class])
```

**Circuit Breaker HTTP Middleware**

You can also protect entire routes or route groups using the `CheckCircuitBreakers` middleware:

```php
// bootstrap/app.php or register as route middleware
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'circuit' => \Kirschbaum\Monitor\Http\Middleware\CheckCircuitBreakers::class,
    ]);
})

// In your routes
Route::middleware(['circuit:payment_gateway,external_api'])
    ->group(function () {
        Route::post('/payments', [PaymentController::class, 'store']);
        Route::get('/external-data', [DataController::class, 'fetch']);
    });

// Or on individual routes
Route::get('/api/data')
    ->middleware('circuit:slow_service')
    ->name('data.fetch');
```

**Circuit Breaker Middleware Features:**
- **Multiple Breakers**: Check multiple circuit breakers with `circuit:breaker1,breaker2,breaker3`
- **Graceful Degradation**: Returns HTTP 503 (Service Unavailable) when circuit is open
- **Standard Headers**: Includes `Retry-After`, `X-Circuit-Breaker`, and `X-Circuit-Breaker-Status` headers
- **Jitter Protection**: Built-in randomized retry delays prevent thundering herd effects
- **Auto-Recovery**: Circuits automatically close when services recover

**Response Headers When Circuit is Open:**
```
HTTP/1.1 503 Service Unavailable
Retry-After: 45
X-Circuit-Breaker: payment_gateway
X-Circuit-Breaker-Status: open
```

The `Retry-After` header includes intelligent jitter - instead of all clients retrying at the exact same time, it provides a random delay between 0 and the remaining decay time, preventing overwhelming the recovering service.

#### **Tracing & Logging**

```php
Monitor::controlled('payment_processing', $this)
    ->overrideTraceId('custom-trace-12345')
    // Origin is automatically set from the second parameter ($this)
```

#### **Complete Example**

```php
class PaymentService
{
    public function processPayment($amount, $userId)
    {
        return Monitor::controlled('payment_processing', $this)
            ->addContext([
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => 'USD'
            ])
            ->withCircuitBreaker('payment_gateway', 3, 120)
            ->withDatabaseTransaction(1, [DeadlockException::class])
            ->catching([
                PaymentDeclinedException::class => function($e, $meta) {
                    return ['status' => 'declined', 'reason' => $e->getMessage()];
                },
                InsufficientFundsException::class => function($e, $meta) {
                    return ['status' => 'insufficient_funds'];
                }
            ])
            ->onUncaughtException(fn($e, $meta) => SomeEscalationLogic::run($e, $meta))
            ->run(function() use ($amount) {
                return $this->chargeCard($amount);
            });
    }
}
```

#### **What it logs:**

**Success:**
```json
{"message": "[Monitor:Services:PaymentService] STARTED", "controlled_block": "payment_processing", "controlled_block_id": "01HK..."}
{"message": "[Monitor:Services:PaymentService] ENDED", "status": "ok", "duration_ms": 1250}
```

**Caught Exception (Recovery):**
```json
{"message": "[Monitor:Services:PaymentService] STARTED", "controlled_block": "payment_processing"}
{"message": "[Monitor:Services:PaymentService] CAUGHT", "exception": "PaymentDeclinedException", "duration_ms": 500}
{"message": "[Monitor:Services:PaymentService] RECOVERED", "recovery_value": "array"}
```

**Uncaught Exception (Escalation):**
```json
{"message": "[Monitor:Services:PaymentService] STARTED", "controlled_block": "payment_processing"}
{"message": "[Monitor:Services:PaymentService] UNCAUGHT", "exception": "RuntimeException", "uncaught": true, "duration_ms": 300}
```

#### **API Reference**

| Method | Purpose | Returns |
|--------|---------|---------|
| `Monitor::controlled(string $name, string\|object $origin = null)` | Create controlled block with optional origin | `self` |
| `->overrideContext(array $context)` | Replace entire context | `self` |
| `->addContext(array $context)` | Merge additional context | `self` |
| `->catching(array $handlers)` | Define exception-specific handlers | `self` |
| `->onUncaughtException(Closure $callback)` | Handle uncaught exceptions only | `self` |
| `->withCircuitBreaker(string $name, int $threshold, int $decay)` | Configure circuit breaker | `self` |
| `->withDatabaseTransaction(int $retries, array $only, array $exclude)` | Wrap in DB transaction with retry | `self` |
| `->overrideTraceId(string $traceId)` | Set custom trace ID | `self` |
| `->run(Closure $callback)` | Execute the controlled block | `mixed` |

### Distributed Tracing

**What it does:** Provides correlation IDs that follow requests across services, jobs, and operations.

```php
use Kirschbaum\Monitor\Facades\Monitor;

class OrderController extends Controller
{
    public function store()
    {
        // Start trace (typically via middleware)
        Monitor::trace()->start();
        
        Monitor::log($this)->info('Processing order');
        
        // All subsequent operations share the same trace ID
        $this->paymentService->charge($amount);
        
        // Queue job with trace context
        ProcessOrderJob::dispatch($order);
    }
}

class PaymentService
{
    public function charge($amount)
    {
        // Automatically includes trace ID from OrderController
        Monitor::log($this)->info('Charging card', ['amount' => $amount]);
    }
}
```

**Trace Management:**
```php
// Manual control
Monitor::trace()->start();            // Generate new UUID (throws if already started)
Monitor::trace()->override($traceId); // Use specific ID (overwrites existing)
Monitor::trace()->pickup($traceId);   // Start if not started, optionally with specific ID
Monitor::trace()->id();               // Get current ID (throws if not started)
Monitor::trace()->hasStarted();       // Check if active
Monitor::trace()->hasNotStarted();    // Check if not active
```

**Key Differences:**
- `start()` - Throws exception if trace already exists
- `override()` - Always sets trace ID, replacing any existing one
- `pickup()` - Safe method that starts only if not already started

### HTTP Middleware

**What it does:** Automatically manages trace IDs for HTTP requests, enabling seamless distributed tracing across services.

**Registration:**
```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Kirschbaum\Monitor\Http\Middleware\StartMonitorTrace::class);
})
```

**Behavior:**
- **Incoming:** Picks up `X-Trace-Id` header or generates new UUID
- **Outgoing:** Sets `X-Trace-Id` header in response
- **Preserves:** Existing traces when already started

**Cross-service usage:**
```php
// Service A
$response = Http::withHeaders([
    'X-Trace-Id' => Monitor::trace()->id()
])->get('https://service-b.example.com/api/data');

// Service B automatically uses the same trace ID
```

**Configuration:** Custom header name via `trace_header` config or `MONITOR_TRACE_HEADER` env var.

### Performance Timing

**What it does:** Provides millisecond-precision timing for operations.

```php
use Kirschbaum\Monitor\Facades\Monitor;

class DataProcessor
{
    public function processData()
    {
        $timer = Monitor::time(); // Auto-starts
        
        // Your processing code
        $this->heavyOperation();
        
        $elapsed = $timer->elapsed(); // Milliseconds
        
        Monitor::log($this)->info('Processing complete', [
            'duration_ms' => $elapsed
        ]);
    }
}
```

**Note:** All Monitor logging automatically includes `duration_ms` from service start.

### Circuit Breaker Direct Access

**What it does:** Provides direct access to circuit breaker state management for advanced use cases.

```php
use Kirschbaum\Monitor\Facades\Monitor;

// Check circuit breaker state
$isOpen = Monitor::breaker()->isOpen('payment_gateway');
$state = Monitor::breaker()->getState('payment_gateway');

// Manual state management
Monitor::breaker()->recordFailure('api_service', 300); // Record failure with 300s decay
Monitor::breaker()->recordSuccess('api_service');      // Record success (resets failures)
Monitor::breaker()->reset('api_service');              // Force reset
Monitor::breaker()->forceOpen('api_service');          // Force open state
```

**Usage in Custom Logic:**
```php
class ExternalApiService
{
    public function makeRequest()
    {
        if (Monitor::breaker()->isOpen('external_api')) {
            return $this->getCachedResponse();
        }
        
        try {
            $response = $this->performApiCall();
            Monitor::breaker()->recordSuccess('external_api');
            return $response;
        } catch (Exception $e) {
            Monitor::breaker()->recordFailure('external_api', 120);
            throw $e;
        }
    }
}
```

### Log Redactor Direct Access

**What it does:** Provides direct access to the redactor for custom redaction needs.

```php
use Kirschbaum\Monitor\Facades\Monitor;

// Direct redaction using configured profile
$redactedData = Monitor::redactor()->redact($sensitiveData);

// Custom profile redaction
$redactedData = Monitor::redactor()->redact($sensitiveData, 'strict');

// Example usage
class UserDataProcessor
{
    public function processUserData(array $userData)
    {
        // Redact before logging or storing
        $safeData = Monitor::redactor()->redact($userData);
        
        Monitor::log($this)->info('Processing user data', $safeData);
        
        return $this->process($userData); // Use original for processing
    }
}
```

### Log Redaction

**What it does:** Automatically scrubs sensitive data from log context using [Kirschbaum Redactor](https://github.com/kirschbaum-development/redactor) to ensure compliance and security while preserving important data.

**Configuration:** Simple redaction configuration in `config/monitor.php`:

```php
'redactor' => [
    'enabled' => true,
    'redactor_profile' => 'default', // Uses Kirschbaum Redactor profiles
],
```

**Usage:** Redaction is automatically applied to all Monitor log context:

```php
Monitor::log($this)->info('User data', [
    'id' => 123,
    'email' => 'user@example.com',    // → '[REDACTED]' based on profile rules
    'password' => 'secret123',        // → '[REDACTED]' based on profile rules
    'api_token' => 'sk-1234567890abcdef...', // → '[REDACTED]' based on profile rules
    'name' => 'John Doe',             // → 'John Doe' (if allowed by profile)
]);
```

For detailed redaction configuration, rules, patterns, and profiles, see the [Kirschbaum Redactor documentation](https://github.com/kirschbaum-development/redactor).

## Complete API Reference

The Monitor facade provides access to all monitoring components:

```php
use Kirschbaum\Monitor\Facades\Monitor;

// Structured logging
Monitor::log($origin)->info('message', $context);

// Controlled execution blocks
Monitor::controlled($name, $origin)->run($callback);

// Distributed tracing
Monitor::trace()->start();
Monitor::trace()->pickup($traceId);

// Performance timing
Monitor::time()->elapsed();

// Circuit breaker management
Monitor::breaker()->isOpen($name);

// Log redaction
Monitor::redactor()->redact($data);
```

All components integrate seamlessly and share trace context automatically when used together.

## Configuration

**Environment Variables:**
```bash
# Core settings
MONITOR_ENABLED=true

# Exception tracing (applies to Controlled blocks only)
MONITOR_TRACE_ENABLED=true
MONITOR_TRACE_FULL_ON_DEBUG=true
MONITOR_TRACE_FORCE_FULL_TRACE=false
MONITOR_TRACE_MAX_LINES=15

# Auto-trace console commands
MONITOR_CONSOLE_AUTO_TRACE_ENABLED=true
MONITOR_CONSOLE_AUTO_TRACE_ENABLE_IN_TESTING=false

# HTTP trace header
MONITOR_TRACE_HEADER=X-Trace-Id

# Circuit breaker defaults
MONITOR_CIRCUIT_BREAKER_DECAY_SECONDS=300
MONITOR_CIRCUIT_BREAKER_RETRY_AFTER=300
MONITOR_CIRCUIT_BREAKER_CORS_HEADERS=false

# Log redaction
MONITOR_REDACTOR_ENABLED=true
MONITOR_REDACTOR_PROFILE=default
```

**Logging Channel:** Configure a dedicated Monitor logging channel:

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

## Output Examples

**Structured Log Entry:**
```json
{
    "level": "info",
    "event": "Monitor:Http:Controllers:UserController:info", 
    "message": "[Monitor:Http:Controllers:UserController] User login successful",
    "trace_id": "9d2b4e8f-3a1c-4d5e-8f2a-1b3c4d5e6f7g",
    "context": {
        "user_id": 123,
        "ip_address": "192.168.1.1",
        "_redacted": true
    },
    "timestamp": "2024-01-15T14:30:45.123Z",
    "duration_ms": 1245,
    "memory_mb": 45.23
}
```

**Controlled Block Execution:**
```json
{"message": "[Monitor:Services:PaymentService] STARTED", "controlled_block": "payment_processing", "controlled_block_id": "01HK4...", "trace_id": "9d2b4e8f..."}
{"message": "[Monitor:Services:PaymentService] ENDED", "controlled_block": "payment_processing", "status": "ok", "duration_ms": 1250}
```

**Failure with Exception:**
```json
{
    "message": "[Monitor:Services:PaymentService] UNCAUGHT",
    "controlled_block": "payment_processing", 
    "exception": {
        "class": "RuntimeException",
        "message": "Card declined",
        "file": "/app/PaymentService.php",
        "line": 45,
        "trace": ["...", "..."]
    },
    "duration_ms": 500,
    "uncaught": true
}
```

## Testing

Run the test suite:

```bash
vendor/bin/pest
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
