<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Kirschbaum\Monitor\CircuitBreaker;
use Kirschbaum\Monitor\LogTimer;
use Kirschbaum\Monitor\Monitor;
use Kirschbaum\Monitor\MonitorWithOrigin;
use Kirschbaum\Monitor\StructuredLogger;
use Kirschbaum\Monitor\Trace;

it('returns Trace instance from service container', function () {
    $monitor = new Monitor;

    $trace = $monitor->trace();

    expect($trace)->toBeInstanceOf(Trace::class);
});

it('returns same Trace instance for multiple calls', function () {
    $monitor = new Monitor;

    $trace1 = $monitor->trace();
    $trace2 = $monitor->trace();

    // Should be same instance since Trace is registered as singleton
    expect($trace1)->toBe($trace2)
        ->and($trace1)->toBeInstanceOf(Trace::class)
        ->and($trace2)->toBeInstanceOf(Trace::class);
});

it('returns StructuredLogger instance with default origin', function () {
    $monitor = new Monitor;

    $logger = $monitor->log();

    expect($logger)->toBeInstanceOf(StructuredLogger::class);
});

it('returns StructuredLogger instance with custom string origin', function () {
    $monitor = new Monitor;

    $logger = $monitor->log('CustomOrigin');

    expect($logger)->toBeInstanceOf(StructuredLogger::class);
});

it('returns StructuredLogger instance with object origin', function () {
    $monitor = new Monitor;

    $objectOrigin = new class
    {
        public function __toString(): string
        {
            return 'ObjectOrigin';
        }
    };

    $logger = $monitor->log($objectOrigin);

    expect($logger)->toBeInstanceOf(StructuredLogger::class);
});

it('returns LogTimer instance from service container', function () {
    $monitor = new Monitor;

    $timer = $monitor->time();

    expect($timer)->toBeInstanceOf(LogTimer::class);
});

it('returns same LogTimer instance for multiple calls', function () {
    $monitor = new Monitor;

    $timer1 = $monitor->time();
    $timer2 = $monitor->time();

    // Should be same instance since LogTimer is registered as singleton
    expect($timer1)->toBe($timer2)
        ->and($timer1)->toBeInstanceOf(LogTimer::class)
        ->and($timer2)->toBeInstanceOf(LogTimer::class);
});

it('returns CircuitBreaker instance from service container', function () {
    $monitor = new Monitor;

    $breaker = $monitor->breaker();

    expect($breaker)->toBeInstanceOf(CircuitBreaker::class);
});

it('returns same CircuitBreaker instance for multiple calls', function () {
    $monitor = new Monitor;

    $breaker1 = $monitor->breaker();
    $breaker2 = $monitor->breaker();

    // Should be same instance since CircuitBreaker is registered as singleton
    expect($breaker1)->toBe($breaker2)
        ->and($breaker1)->toBeInstanceOf(CircuitBreaker::class)
        ->and($breaker2)->toBeInstanceOf(CircuitBreaker::class);
});

it('returns MonitorWithOrigin instance with string origin', function () {
    $monitor = new Monitor;

    $monitorWithOrigin = $monitor->from('TestOrigin');

    expect($monitorWithOrigin)->toBeInstanceOf(MonitorWithOrigin::class);
});

it('returns MonitorWithOrigin instance with object origin', function () {
    $monitor = new Monitor;

    $objectOrigin = new class
    {
        public function __toString(): string
        {
            return 'ObjectOrigin';
        }
    };

    $monitorWithOrigin = $monitor->from($objectOrigin);

    expect($monitorWithOrigin)->toBeInstanceOf(MonitorWithOrigin::class);
});

it('creates controlled execution block and returns result', function () {
    $monitor = new Monitor;

    $result = $monitor->controlled('test_operation')->run(function () {
        return 'operation_result';
    });

    expect($result)->toBe('operation_result');
});

it('creates controlled execution block with context', function () {
    $monitor = new Monitor;

    $context = ['user_id' => 123, 'action' => 'test'];

    $result = $monitor->controlled('test_with_context')
        ->context($context)
        ->run(function () {
            return 'success';
        });

    expect($result)->toBe('success');
});

it('throws exception when controlled block name is missing', function () {
    $monitor = new Monitor;

    expect(fn () => $monitor->controlled()->run(fn () => 'test'))
        ->toThrow(InvalidArgumentException::class, 'Controlled block name is required');
});

it('passes through controlled block exceptions correctly', function () {
    $monitor = new Monitor;

    expect(fn () => $monitor->controlled('failing_operation')->run(function () {
        throw new \RuntimeException('Operation failed');
    }))
        ->toThrow(\RuntimeException::class, 'Operation failed');
});

it('handles empty context array correctly', function () {
    $monitor = new Monitor;

    $result = $monitor->controlled('empty_context_test')
        ->context([])
        ->run(function () {
            return 'context_success';
        });

    expect($result)->toBe('context_success');
});

it('handles complex context data correctly', function () {
    $monitor = new Monitor;

    $complexContext = [
        'user' => ['id' => 456, 'name' => 'Test User'],
        'operation' => 'complex_test',
        'metadata' => ['timestamp' => time(), 'version' => '1.0'],
    ];

    $result = $monitor->controlled('complex_context_test')
        ->context($complexContext)
        ->run(function () {
            return 'complex_success';
        });

    expect($result)->toBe('complex_success');
});

it('routes to correct underlying services', function () {
    $monitor = new Monitor;

    // Test that all methods return the expected types
    expect($monitor->trace())->toBeInstanceOf(Trace::class)
        ->and($monitor->log())->toBeInstanceOf(StructuredLogger::class)
        ->and($monitor->log('test'))->toBeInstanceOf(StructuredLogger::class)
        ->and($monitor->time())->toBeInstanceOf(LogTimer::class)
        ->and($monitor->breaker())->toBeInstanceOf(CircuitBreaker::class)
        ->and($monitor->from('test'))->toBeInstanceOf(MonitorWithOrigin::class);
});

it('handles different return types from controlled block callbacks', function () {
    $monitor = new Monitor;

    // Test different return types
    $stringResult = $monitor->controlled('string_test')->run(fn () => 'string');
    $intResult = $monitor->controlled('int_test')->run(fn () => 42);
    $arrayResult = $monitor->controlled('array_test')->run(fn () => ['key' => 'value']);
    $nullResult = $monitor->controlled('null_test')->run(fn () => null);
    $boolResult = $monitor->controlled('bool_test')->run(fn () => true);

    expect($stringResult)->toBe('string')
        ->and($intResult)->toBe(42)
        ->and($arrayResult)->toBe(['key' => 'value'])
        ->and($nullResult)->toBeNull()
        ->and($boolResult)->toBeTrue();
});

it('preserves controlled block callback parameter and return types', function () {
    $monitor = new Monitor;

    $callback = function (string $input): string {
        return "processed: {$input}";
    };

    // Test that closures work correctly within controlled blocks
    $result = $monitor->controlled('closure_test')->run(function () use ($callback) {
        return $callback('test_data');
    });

    expect($result)->toBe('processed: test_data');
});
