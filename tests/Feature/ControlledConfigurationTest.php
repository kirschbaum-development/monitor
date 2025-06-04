<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Controlled;
use RuntimeException;

beforeEach(function () {
    // Mock logging to prevent actual log output during tests
    Log::shouldReceive('info')->byDefault();
    Log::shouldReceive('critical')->byDefault();
    Log::shouldReceive('warning')->byDefault();
    Log::shouldReceive('error')->byDefault();
});

describe('Controlled Configuration Tests', function () {
    describe('Exception Trace Configuration', function () {
        it('returns null when exception trace is disabled', function () {
            Config::set('monitor.exception_trace.enabled', false);

            $controlled = Controlled::for('trace-disabled-test')
                ->catching([
                    RuntimeException::class => function ($exception, $meta) {
                        // Check that exception details are null when tracing is disabled
                        expect($meta['exception'])->toBeNull();
                        throw $exception; // Re-throw to maintain test expectation
                    },
                ]);

            expect(fn () => $controlled->run(function () {
                throw new RuntimeException('Test exception');
            }))->toThrow(RuntimeException::class);
        });

        it('includes exception data when trace is enabled', function () {
            Config::set('monitor.exception_trace.enabled', true);
            Config::set('app.debug', true);
            Config::set('monitor.exception_trace.full_on_debug', true);

            $exceptionData = null;
            $controlled = Controlled::for('trace-enabled-test')
                ->catching([
                    RuntimeException::class => function ($exception, $meta) use (&$exceptionData) {
                        $exceptionData = $meta['exception'];
                        throw $exception; // Re-throw to maintain test expectation
                    },
                ]);

            expect(fn () => $controlled->run(function () {
                throw new RuntimeException('Test exception with trace');
            }))->toThrow(RuntimeException::class);

            expect($exceptionData)->not->toBeNull()
                ->and($exceptionData['class'])->toBe(RuntimeException::class)
                ->and($exceptionData['message'])->toBe('Test exception with trace');
        });

        it('handles trace configuration when debug mode is disabled', function () {
            Config::set('monitor.exception_trace.enabled', true);
            Config::set('app.debug', false);
            Config::set('monitor.exception_trace.max_lines', 3);

            $exceptionData = null;
            $controlled = Controlled::for('limited-trace-test')
                ->catching([
                    RuntimeException::class => function ($exception, $meta) use (&$exceptionData) {
                        $exceptionData = $meta['exception'];
                        throw $exception; // Re-throw to maintain test expectation
                    },
                ]);

            expect(fn () => $controlled->run(function () {
                throw new RuntimeException('Test exception with limited trace');
            }))->toThrow(RuntimeException::class);

            expect($exceptionData)->not->toBeNull()
                ->and($exceptionData['class'])->toBe(RuntimeException::class);

            // If trace exists, it should be limited
            if (isset($exceptionData['trace'])) {
                expect(count($exceptionData['trace']))->toBeLessThanOrEqual(3);
            }
        });
    });

    describe('Circuit Breaker Without Name', function () {
        it('executes normally when no circuit breaker is configured', function () {
            $controlled = Controlled::for('no-breaker-execution-test');

            $result = $controlled->run(function () {
                return 'success without breaker';
            });

            expect($result)->toBe('success without breaker');
        });

        it('handles failure without circuit breaker name set', function () {
            $controlled = Controlled::for('no-breaker-failure-test');

            expect(fn () => $controlled->run(function () {
                throw new RuntimeException('Failure without breaker');
            }))->toThrow(RuntimeException::class, 'Failure without breaker');
        });
    });

    describe('Uncaught Exception Callback Exception Handling', function () {
        it('logs uncaught exception callback errors without affecting main exception', function () {
            // This tests the uncaught exception callback error handling
            $controlled = Controlled::for('uncaught-exception-error-logging-test')
                ->onUncaughtException(function ($exception, $meta) {
                    throw new RuntimeException('Uncaught exception handler failed');
                })
                ->catching([
                    RuntimeException::class => function ($exception, $meta) {
                        // This should still be called despite uncaught exception handler failure
                        expect($exception)->toBeInstanceOf(RuntimeException::class);
                        throw $exception; // Re-throw to maintain test expectation
                    },
                ]);

            expect(fn () => $controlled->run(function () {
                throw new RuntimeException('Original exception');
            }))->toThrow(RuntimeException::class, 'Original exception');
        });
    });
});
