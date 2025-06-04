<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Facades\Monitor;

it('logs ccp start and end', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, '[CCP:test_ccp] STARTED')
                && ($context['context']['ccp'] ?? null) === 'test_ccp'
                && isset($context['trace_id']);
        });

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, '[CCP:test_ccp] ENDED')
                && ($context['context']['ccp'] ?? null) === 'test_ccp'
                && ($context['context']['status'] ?? null) === 'ok';
        });

    $result = Monitor::ccp('test_ccp', fn () => 'success');

    expect($result)->toBe('success');
});

it('logs ccp failure and throws', function () {
    Config::set('app.debug', false);
    Config::set('monitor.exception_trace.enabled', true);
    Config::set('monitor.exception_trace.full_on_debug', false);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($message, $context) => str_contains($message, '[CCP:test_failure] STARTED')
            && ($context['context']['ccp'] ?? null) === 'test_failure'
        );

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, '[CCP:test_failure] FAILED')
                && ($context['context']['status'] ?? null) === 'failed'
                && ($context['context']['exception']['class'] ?? null) === RuntimeException::class;
        });

    expect(fn () => Monitor::ccp('test_failure', fn () => throw new RuntimeException('Something went wrong')))
        ->toThrow(RuntimeException::class, 'Something went wrong');
});

it('executes onFail callback when CCP fails', function () {
    Config::set('monitor.exception_trace.enabled', false);

    $callbackExecuted = false;
    $capturedContext = null;
    $capturedException = null;

    Log::shouldReceive('info')->once(); // STARTED
    Log::shouldReceive('critical')->once(); // FAILED

    $onFail = function ($exception, $context) use (&$callbackExecuted, &$capturedContext, &$capturedException) {
        $callbackExecuted = true;
        $capturedContext = $context;
        $capturedException = $exception;
    };

    expect(fn () => Monitor::ccp('callback_test', function () {
        throw new RuntimeException('Test failure');
    }, ['user_id' => 123], $onFail))
        ->toThrow(RuntimeException::class, 'Test failure');

    expect($callbackExecuted)->toBeTrue()
        ->and($capturedException)->toBeInstanceOf(RuntimeException::class)
        ->and($capturedException?->getMessage())->toBe('Test failure')
        ->and($capturedContext['ccp'])->toBe('callback_test')
        ->and($capturedContext['user_id'])->toBe(123)
        ->and($capturedContext['status'])->toBe('failed');
});

it('does not execute onFail callback when CCP succeeds', function () {
    $callbackExecuted = false;

    Log::shouldReceive('info')->twice(); // STARTED and ENDED

    $onFail = function () use (&$callbackExecuted) {
        $callbackExecuted = true;
    };

    $result = Monitor::ccp('success_test', fn () => 'success', [], $onFail);

    expect($result)->toBe('success')
        ->and($callbackExecuted)->toBeFalse();
});

it('handles onFail callback exceptions gracefully', function () {
    Config::set('monitor.exception_trace.enabled', false);

    Log::shouldReceive('info')->once(); // STARTED
    Log::shouldReceive('critical')->once(); // FAILED
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, '[CCP:callback_error_test] FAILURE_CALLBACK_ERROR')
                && ($context['context']['original_exception'] ?? null) === 'Original failure'
                && ($context['context']['callback_exception'] ?? null) === 'Callback failed';
        });

    $onFail = function () {
        throw new RuntimeException('Callback failed');
    };

    expect(fn () => Monitor::ccp('callback_error_test', function () {
        throw new RuntimeException('Original failure');
    }, [], $onFail))
        ->toThrow(RuntimeException::class, 'Original failure');
});

it('works without onFail callback (backward compatibility)', function () {
    Log::shouldReceive('info')->twice(); // STARTED and ENDED

    $result = Monitor::ccp('backward_compat_test', fn () => 'success', ['key' => 'value']);

    expect($result)->toBe('success');
});

it('passes correct context data to onFail callback', function () {
    Config::set('monitor.exception_trace.enabled', false);

    $capturedContext = null;

    Log::shouldReceive('info')->once(); // STARTED
    Log::shouldReceive('critical')->once(); // FAILED

    $onFail = function ($exception, $context) use (&$capturedContext) {
        $capturedContext = $context;
    };

    expect(fn () => Monitor::ccp('context_test', function () {
        throw new RuntimeException('Test');
    }, ['custom_key' => 'custom_value', 'user_id' => 456], $onFail))
        ->toThrow(RuntimeException::class);

    expect($capturedContext)->toHaveKeys([
        'ccp', 'ccp_id', 'trace_id', 'duration_ms', 'ccp_duration_ms', 'status',
        'custom_key', 'user_id',
    ])
        ->and($capturedContext['ccp'])->toBe('context_test')
        ->and($capturedContext['custom_key'])->toBe('custom_value')
        ->and($capturedContext['user_id'])->toBe(456)
        ->and($capturedContext['status'])->toBe('failed');
});

it('allows onFail callback to perform escalation actions', function () {
    Config::set('monitor.exception_trace.enabled', false);

    $alertSent = false;
    $circuitBreakerOpened = false;
    $healthCheckUpdated = false;

    Log::shouldReceive('info')->once(); // STARTED
    Log::shouldReceive('critical')->once(); // FAILED

    $onFail = function ($exception, $context) use (&$alertSent, &$circuitBreakerOpened, &$healthCheckUpdated) {
        // Simulate escalation actions
        $alertSent = true; // NotificationService::alertOps()
        $circuitBreakerOpened = true; // CircuitBreaker::open()
        $healthCheckUpdated = true; // HealthCheck::markDegraded()
    };

    expect(fn () => Monitor::ccp('escalation_test', function () {
        throw new RuntimeException('Critical failure');
    }, [], $onFail))
        ->toThrow(RuntimeException::class, 'Critical failure');

    expect($alertSent)->toBeTrue()
        ->and($circuitBreakerOpened)->toBeTrue()
        ->and($healthCheckUpdated)->toBeTrue();
});

it('provides complete exception information to onFail callback', function () {
    Config::set('monitor.exception_trace.enabled', true);
    Config::set('app.debug', true);
    Config::set('monitor.exception_trace.full_on_debug', true);

    $capturedException = null;
    $capturedContext = null;

    Log::shouldReceive('info')->once(); // STARTED
    Log::shouldReceive('critical')->once(); // FAILED

    $onFail = function ($exception, $context) use (&$capturedException, &$capturedContext) {
        $capturedException = $exception;
        $capturedContext = $context;
    };

    expect(fn () => Monitor::ccp('exception_info_test', function () {
        throw new InvalidArgumentException('Detailed error message');
    }, [], $onFail))
        ->toThrow(InvalidArgumentException::class, 'Detailed error message');

    expect($capturedException)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($capturedException?->getMessage())->toBe('Detailed error message')
        ->and($capturedContext['exception']['class'])->toBe(InvalidArgumentException::class)
        ->and($capturedContext['exception']['message'])->toBe('Detailed error message')
        ->and($capturedContext['exception'])->toHaveKeys(['class', 'message', 'file', 'line', 'trace']);
});

// NEW COMPREHENSIVE TESTS:

it('does not include exception trace when disabled', function () {
    Config::set('monitor.exception_trace.enabled', false);  // This tests line 67

    Log::shouldReceive('info')->once();  // STARTED log

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            // Exception should NOT be included when tracing is disabled
            return str_contains($message, '[CCP:disabled_trace] FAILED')
                && ($context['context']['status'] ?? null) === 'failed'
                && ! isset($context['context']['exception']);
        });

    expect(fn () => Monitor::ccp('disabled_trace', fn () => throw new RuntimeException('Test error')))
        ->toThrow(RuntimeException::class);
});

it('includes full trace when debug mode is enabled', function () {
    Config::set('app.debug', true);
    Config::set('monitor.exception_trace.enabled', true);
    Config::set('monitor.exception_trace.full_on_debug', true);

    Log::shouldReceive('info')->once();  // STARTED log

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            $exception = $context['context']['exception'] ?? null;

            return $exception !== null
                && is_array($exception['trace'])
                && count($exception['trace']) > 5;  // Full trace should be longer
        });

    expect(fn () => Monitor::ccp('full_trace', fn () => throw new RuntimeException('Full trace test')))
        ->toThrow(RuntimeException::class);
});

it('truncates trace when not in debug mode', function () {
    Config::set('app.debug', false);
    Config::set('monitor.exception_trace.enabled', true);
    Config::set('monitor.exception_trace.full_on_debug', true);
    Config::set('monitor.exception_trace.max_lines', 3);  // Small limit for testing

    Log::shouldReceive('info')->once();  // STARTED log

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            $exception = $context['context']['exception'] ?? null;

            return $exception !== null
                && is_array($exception['trace'])
                && count($exception['trace']) === 3;  // Should be truncated to 3 lines
        });

    expect(fn () => Monitor::ccp('truncated_trace', fn () => throw new RuntimeException('Truncated trace test')))
        ->toThrow(RuntimeException::class);
});

it('forces full trace when force_full_trace is enabled', function () {
    Config::set('app.debug', false);  // Debug off
    Config::set('monitor.exception_trace.enabled', true);
    Config::set('monitor.exception_trace.force_full_trace', true);  // But force full trace
    Config::set('monitor.exception_trace.full_on_debug', true);

    Log::shouldReceive('info')->once();  // STARTED log

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            $exception = $context['context']['exception'] ?? null;

            return $exception !== null
                && is_array($exception['trace'])
                && count($exception['trace']) > 5;  // Should be full trace despite debug=false
        });

    expect(fn () => Monitor::ccp('forced_trace', fn () => throw new RuntimeException('Forced trace test')))
        ->toThrow(RuntimeException::class);
});

it('includes custom context in both success and failure logs', function () {
    $customContext = ['user_id' => 123, 'action' => 'test_action'];

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, '[CCP:context_test] STARTED')
                && ($context['context']['user_id'] ?? null) === 123
                && ($context['context']['action'] ?? null) === 'test_action';
        });

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, '[CCP:context_test] ENDED')
                && ($context['context']['user_id'] ?? null) === 123
                && ($context['context']['action'] ?? null) === 'test_action'
                && ($context['context']['status'] ?? null) === 'ok';
        });

    $result = Monitor::ccp('context_test', fn () => 'success', $customContext);

    expect($result)->toBe('success');
});

it('preserves custom context in failure logs', function () {
    $customContext = ['user_id' => 456, 'critical_action' => true];

    Config::set('monitor.exception_trace.enabled', false); // No exception details

    Log::shouldReceive('info')->once();  // STARTED log

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, '[CCP:context_failure] FAILED')
                && ($context['context']['user_id'] ?? null) === 456
                && ($context['context']['critical_action'] ?? null) === true
                && ($context['context']['status'] ?? null) === 'failed';
        });

    expect(fn () => Monitor::ccp('context_failure', fn () => throw new RuntimeException('Context failure'), $customContext))
        ->toThrow(RuntimeException::class);
});

it('includes all required fields in exception trace', function () {
    Config::set('monitor.exception_trace.enabled', true);

    Log::shouldReceive('info')->once();  // STARTED log

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            $exception = $context['context']['exception'] ?? null;
            if (! $exception) {
                return false;
            }

            $requiredFields = ['class', 'message', 'file', 'line', 'trace'];
            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $exception)) {
                    return false;
                }
            }

            return $exception['class'] === RuntimeException::class
                && $exception['message'] === 'Complete exception test'
                && is_string($exception['file'])
                && is_int($exception['line'])
                && is_array($exception['trace']);
        });

    expect(fn () => Monitor::ccp('complete_exception', fn () => throw new RuntimeException('Complete exception test')))
        ->toThrow(RuntimeException::class);
});

it('respects debug OR force_full_trace logic correctly', function () {
    // CRITICAL: This test catches the BooleanOrToBooleanAnd mutation
    // The logic should be: $isDebug = Config::boolean('app.debug') || Config::boolean('monitor.exception_trace.force_full_trace', false);
    // If mutated to &&, this test will fail

    Config::set('app.debug', false);  // Debug is OFF
    Config::set('monitor.exception_trace.enabled', true);
    Config::set('monitor.exception_trace.force_full_trace', true);  // But force is ON
    Config::set('monitor.exception_trace.full_on_debug', true);

    Log::shouldReceive('info')->once();  // STARTED log

    Log::shouldReceive('critical')
        ->once()
        ->withArgs(function ($message, $context) {
            $exception = $context['context']['exception'] ?? null;

            // Should get FULL trace because force_full_trace=true, even though debug=false
            // If mutation changes || to &&, this would fail because debug=false AND force=true = false
            return $exception !== null
                && is_array($exception['trace'])
                && count($exception['trace']) > 5;  // Full trace (not truncated)
        });

    expect(fn () => Monitor::ccp('debug_or_force', fn () => throw new RuntimeException('Debug OR force logic test')))
        ->toThrow(RuntimeException::class);
});
