# Laravel Monitor

![Laravel Supported Versions](https://img.shields.io/badge/laravel-10.x/11.x/12.x-green.svg)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirschbaum-development/monitor.svg?style=flat-square)](https://packagist.org/packages/kirschbaum-development/monitor)
![Application Testing](https://github.com/kirschbaum-development/monitor/actions/workflows/php-tests.yml/badge.svg)
![Static Analysis](https://github.com/kirschbaum-development/monitor/actions/workflows/static-analysis.yml/badge.svg)
![Code Style](https://github.com/kirschbaum-development/monitor/actions/workflows/style-check.yml/badge.svg)

Laravel Monitor is an observability helper / toolkit for Laravel applications. .

## Table of Contents

- [Installation](#installation)
- [Components](#components)
  - [Structured Logging](#structured-logging)
  - [Controlled Execution Blocks](#controlled-execution-blocks)
  - [Distributed Tracing](#distributed-tracing)
  - [HTTP Middleware](#http-middleware)
  - [Performance Timing](#performance-timing)
  - [Log Redaction](#log-redaction)
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
        Monitor::from($this)->info('User login attempt', [
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
        Monitor::from($this)->info('Processing payment', [
            'amount' => $amount,
            'processor' => 'stripe'
        ]);
    }
}
```

**Note:** While you can override with `Monitor::from('CustomName')`, using `from($this)` is preferred as it automatically provides meaningful, consistent origin tracking from your actual class structure.

**What it logs:**
```json
{
    "level": "info",
    "event": "Api:UserController:info",
    "message": "[Api:UserController] User login attempt",
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

**Configuration:** Origin path replacers, separators, and wrappers control how class names appear in logs:

```php
// config/monitor.php
'origin_path_replacers' => [
    'App\\Http\\Controllers\\' => '',        // Remove controller namespace
    'App\\Services\\Payment\\' => 'Pay\\',   // Shorten payment services
    'App\\Services\\' => 'Svc\\',            // General service shortening
],
'origin_separator' => ':',           // App\Http\Controllers\Api\UserController → Api:UserController  
'origin_path_wrapper' => 'square',   // Api:UserController → [Api:UserController]
```

### Controlled Execution Blocks

**What it does:** Monitors critical operations with automatic start/end logging, exception handling, circuit breakers, and failure callbacks.

```php
use Kirschbaum\Monitor\Facades\Monitor;

class PaymentService
{
    public function processPayment($amount, $userId)
    {
        return Monitor::controlled('payment_processing')
            ->context(['amount' => $amount, 'user_id' => $userId])
            ->failing(function ($exception, $context) {
                // Alert ops team immediately
                NotificationService::alertOps('Payment failure', $context);
                
                // Open circuit breaker
                CircuitBreaker::open('payment_service', '5 minutes');
            })
            ->run(function () use ($amount) {
                return $this->chargeCard($amount);
            });
    }
}
```

**What it logs:**
```json
// Success
{"message": "[PaymentService] STARTED", "controlled_block": "payment_processing", "block_id": "01HK..."}
{"message": "[PaymentService] ENDED", "status": "ok", "duration_ms": 1250}

// Failure  
{"message": "[PaymentService] STARTED", "controlled_block": "payment_processing"}
{"message": "[PaymentService] FAILED", "exception": "RuntimeException", "trace": [...]}
```

**Advanced Features:**
- **Circuit Breakers:** `->breaker('service_name', threshold, decaySeconds)`
- **Database Transactions:** `->transactioned(retries, onlyExceptions, excludeExceptions)`
- **Failure Escalation:** `->escalated($callback)` for critical business processes

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
        
        Monitor::from($this)->info('Processing order');
        
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
        Monitor::from($this)->info('Charging card', ['amount' => $amount]);
    }
}
```

**Trace Management:**
```php
// Manual control
Monitor::trace()->start();            // Generate new UUID
Monitor::trace()->override($traceId); // Use specific ID
Monitor::trace()->id();               // Get current ID
Monitor::trace()->hasStarted();       // Check if active
```

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
        
        Monitor::from($this)->info('Processing complete', [
            'duration_ms' => $elapsed
        ]);
    }
}
```

**Note:** All Monitor logging automatically includes `duration_ms` from service start.

### Log Redaction

**What it does:** Automatically scrubs sensitive data from log context to ensure compliance and security.

**Configuration:** Redaction options in `config/monitor.php`:

```php
'log_redactor' => [
    'enabled' => true,
    'redact_keys' => [
        'password', 'token', 'api_key', 'authorization', 
        'ssn', 'credit_card', 'private_key'
    ],
    'patterns' => [
        'email' => '/[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+/',
        'credit_card' => '/\b(?:\d[ -]*?){13,16}\b/',
        'bearer_token' => '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/',
    ],
    'replacement' => '[REDACTED]',
    'max_value_length' => 10000,     // Truncate large values
    'redact_large_objects' => true,  // Limit large arrays/objects
    'max_object_size' => 50,
],
```

**What happens:**
```php
Monitor::from($this)->info('User data', [
    'email' => 'user@example.com',    // → '[REDACTED]'
    'password' => 'secret123',        // → '[REDACTED]'  
    'token' => 'Bearer abc123',       // → '[REDACTED]'
    'name' => 'John Doe'              // → 'John Doe' (unchanged)
]);
```

## Configuration

**Environment Variables:**
```bash
# Core settings
MONITOR_ENABLED=true

# Exception tracing
MONITOR_TRACE_ENABLED=true
MONITOR_TRACE_FULL_ON_DEBUG=true
MONITOR_TRACE_MAX_LINES=15

# Auto-trace console commands
MONITOR_CONSOLE_AUTO_TRACE_ENABLED=true

# HTTP trace header
MONITOR_TRACE_HEADER=X-Trace-Id

# Log redaction
MONITOR_LOG_REDACTOR_ENABLED=true
MONITOR_LOG_REDACTOR_REPLACEMENT='[REDACTED]'
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
    "event": "UserController:info", 
    "message": "[UserController] User login successful",
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
{"message": "[PaymentService] STARTED", "controlled_block": "payment_processing", "controlled_block_id": "01HK4...", "trace_id": "9d2b4e8f..."}
{"message": "[PaymentService] ENDED", "controlled_block": "payment_processing", "status": "ok", "duration_ms": 1250}
```

**Failure with Exception:**
```json
{
    "message": "[PaymentService] FAILED",
    "controlled_block": "payment_processing", 
    "exception": {
        "class": "RuntimeException",
        "message": "Card declined",
        "file": "/app/PaymentService.php",
        "line": 45,
        "trace": ["...", "..."]
    },
    "duration_ms": 500
}
```

## Testing

Run the test suite:

```bash
vendor/bin/pest
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
