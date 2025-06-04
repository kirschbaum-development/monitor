<?php

declare(strict_types=1);

namespace Tests\Feature;

use InvalidArgumentException;
use Kirschbaum\Monitor\Ccp;
use Kirschbaum\Monitor\LogTimer;
use Kirschbaum\Monitor\Monitor;
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

it('executes CCP block with callback and returns result', function () {
    $monitor = new Monitor;

    $result = $monitor->ccp('test_operation', function () {
        return 'operation_result';
    });

    expect($result)->toBe('operation_result');
});

it('executes CCP block with callback and context', function () {
    $monitor = new Monitor;

    $context = ['user_id' => 123, 'action' => 'test'];

    $result = $monitor->ccp('test_with_context', function () {
        return 'success';
    }, $context);

    expect($result)->toBe('success');
});

it('throws exception when CCP callback is null', function () {
    $monitor = new Monitor;

    expect(fn () => $monitor->ccp('test_operation', null))
        ->toThrow(InvalidArgumentException::class, 'Callback is required for CCP blocks.');
});

it('throws exception when CCP callback is not provided', function () {
    $monitor = new Monitor;

    expect(fn () => $monitor->ccp('test_operation'))
        ->toThrow(InvalidArgumentException::class, 'Callback is required for CCP blocks.');
});

it('passes through CCP exceptions correctly', function () {
    $monitor = new Monitor;

    expect(fn () => $monitor->ccp('failing_operation', function () {
        throw new \RuntimeException('Operation failed');
    }))
        ->toThrow(\RuntimeException::class, 'Operation failed');
});

it('handles empty context array correctly', function () {
    $monitor = new Monitor;

    $result = $monitor->ccp('empty_context_test', function () {
        return 'context_success';
    }, []);

    expect($result)->toBe('context_success');
});

it('handles complex context data correctly', function () {
    $monitor = new Monitor;

    $complexContext = [
        'user' => ['id' => 456, 'name' => 'Test User'],
        'operation' => 'complex_test',
        'metadata' => ['timestamp' => time(), 'version' => '1.0'],
    ];

    $result = $monitor->ccp('complex_context_test', function () {
        return 'complex_success';
    }, $complexContext);

    expect($result)->toBe('complex_success');
});

it('routes to correct underlying services', function () {
    $monitor = new Monitor;

    // Test that all methods return the expected types
    expect($monitor->trace())->toBeInstanceOf(Trace::class)
        ->and($monitor->log())->toBeInstanceOf(StructuredLogger::class)
        ->and($monitor->log('test'))->toBeInstanceOf(StructuredLogger::class)
        ->and($monitor->time())->toBeInstanceOf(LogTimer::class);
});

it('handles different return types from CCP callbacks', function () {
    $monitor = new Monitor;

    // Test different return types
    $stringResult = $monitor->ccp('string_test', fn () => 'string');
    $intResult = $monitor->ccp('int_test', fn () => 42);
    $arrayResult = $monitor->ccp('array_test', fn () => ['key' => 'value']);
    $nullResult = $monitor->ccp('null_test', fn () => null);
    $boolResult = $monitor->ccp('bool_test', fn () => true);

    expect($stringResult)->toBe('string')
        ->and($intResult)->toBe(42)
        ->and($arrayResult)->toBe(['key' => 'value'])
        ->and($nullResult)->toBeNull()
        ->and($boolResult)->toBeTrue();
});

it('preserves CCP callback parameter and return types', function () {
    $monitor = new Monitor;

    $callback = function (string $input): string {
        return "processed: {$input}";
    };

    // Note: We can't pass parameters to the CCP callback through this interface
    // but we can test that closures work correctly
    $result = $monitor->ccp('closure_test', function () use ($callback) {
        return $callback('test_data');
    });

    expect($result)->toBe('processed: test_data');
});
