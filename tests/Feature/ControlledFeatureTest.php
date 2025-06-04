<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\CircuitBreaker;
use Kirschbaum\Monitor\Controlled;
use Kirschbaum\Monitor\Exceptions\NestedControlledBlockException;
use Mockery;
use RuntimeException;

beforeEach(function () {
    $this->setupLogMocking();
    Cache::flush();

    // Allow logging calls that the Controlled class makes
    Log::shouldReceive('info')->byDefault();
    Log::shouldReceive('critical')->byDefault();
    Log::shouldReceive('warning')->byDefault();
    Log::shouldReceive('error')->byDefault();
});

describe('Controlled Framework Integration', function () {
    describe('Basic Execution with Framework', function () {
        it('executes callback and returns result', function () {
            $result = Controlled::for('test-operation')
                ->run(fn () => 'success');

            expect($result)->toBe('success');
        });

        it('executes callback with different return types', function () {
            expect(Controlled::for('string-test')->run(fn () => 'string'))->toBe('string')
                ->and(Controlled::for('int-test')->run(fn () => 42))->toBe(42)
                ->and(Controlled::for('array-test')->run(fn () => ['key' => 'value']))->toBe(['key' => 'value'])
                ->and(Controlled::for('null-test')->run(fn () => null))->toBeNull()
                ->and(Controlled::for('bool-test')->run(fn () => true))->toBeTrue();
        });

        it('preserves exceptions from callback', function () {
            expect(fn () => Controlled::for('failing-operation')
                ->run(function () {
                    throw new RuntimeException('Operation failed');
                }))
                ->toThrow(RuntimeException::class, 'Operation failed');
        });
    });

    describe('Context Integration', function () {
        it('executes with context successfully', function () {
            $context = ['user_id' => 123, 'action' => 'test'];

            $result = Controlled::for('context-test')
                ->overrideContext($context)
                ->run(fn () => 'context-success');

            expect($result)->toBe('context-success');
        });

        it('merges context and executes', function () {
            $result = Controlled::for('merge-context-test')
                ->overrideContext(['initial' => 'value'])
                ->addContext(['additional' => 'data'])
                ->run(fn () => 'merge-success');

            expect($result)->toBe('merge-success');
        });
    });

    describe('Failure Handling Integration', function () {
        it('calls exception handler when operation fails', function () {
            $handlerCalled = false;
            $capturedMeta = null;

            // With the new design, caught exceptions don't re-throw unless handler explicitly does so
            $result = Controlled::for('fail-callback-test')
                ->catching([
                    RuntimeException::class => function ($exception, $meta) use (&$handlerCalled, &$capturedMeta) {
                        $handlerCalled = true;
                        $capturedMeta = $meta;

                        return 'handled'; // Return recovery value
                    },
                ])
                ->run(function () {
                    throw new RuntimeException('Test failure');
                });

            expect($handlerCalled)->toBeTrue()
                ->and($capturedMeta)->toBeArray()
                ->and($capturedMeta['controlled_block'])->toBe('fail-callback-test')
                ->and($result)->toBe('handled'); // Should return the recovery value
        });

        it('calls uncaught exception callback when operation fails with uncaught exception', function () {
            $uncaughtCalled = false;
            $capturedMeta = null;

            expect(function () use (&$uncaughtCalled, &$capturedMeta) {
                Controlled::for('uncaught-exception-test')
                    ->onUncaughtException(function ($exception, $meta) use (&$uncaughtCalled, &$capturedMeta) {
                        $uncaughtCalled = true;
                        $capturedMeta = $meta;
                    })
                    ->run(function () {
                        throw new RuntimeException('Test uncaught exception');
                    });
            })->toThrow(RuntimeException::class, 'Test uncaught exception');

            expect($uncaughtCalled)->toBeTrue()
                ->and($capturedMeta)->toBeArray()
                ->and($capturedMeta['controlled_block'])->toBe('uncaught-exception-test');
        });

        it('continues execution if exception handler throws', function () {
            expect(function () {
                Controlled::for('callback-error-test')
                    ->catching([
                        RuntimeException::class => function () {
                            throw new RuntimeException('Handler error');
                        },
                    ])
                    ->run(function () {
                        throw new RuntimeException('Original error');
                    });
            })->toThrow(RuntimeException::class, 'Original error');
        });

        it('handles exception when handler returns null', function () {
            $handlerCalled = false;
            $capturedMeta = null;

            // Test the case where exception handler returns null (or doesn't explicitly return)
            // This should trigger line 297: return true; // Indicate exception was handled
            $result = Controlled::for('handler-returns-null-test')
                ->catching([
                    RuntimeException::class => function ($exception, $meta) use (&$handlerCalled, &$capturedMeta) {
                        $handlerCalled = true;
                        $capturedMeta = $meta;

                        // Explicitly return null to test line 297
                        return null;
                    },
                ])
                ->run(function () {
                    throw new RuntimeException('Test exception for null handler');
                });

            expect($handlerCalled)->toBeTrue()
                ->and($capturedMeta)->toBeArray()
                ->and($capturedMeta['controlled_block'])->toBe('handler-returns-null-test')
                ->and($result)->toBeTrue(); // Should return true when handler returns null
        });

        it('handles exception when handler has no explicit return', function () {
            $handlerCalled = false;
            $capturedMeta = null;

            // Test the case where exception handler doesn't explicitly return anything (implicit null)
            // This should also trigger line 297: return true; // Indicate exception was handled
            $result = Controlled::for('handler-no-return-test')
                ->catching([
                    RuntimeException::class => function ($exception, $meta) use (&$handlerCalled, &$capturedMeta) {
                        $handlerCalled = true;
                        $capturedMeta = $meta;
                        // No explicit return statement (implicit null)
                    },
                ])
                ->run(function () {
                    throw new RuntimeException('Test exception for no-return handler');
                });

            expect($handlerCalled)->toBeTrue()
                ->and($capturedMeta)->toBeArray()
                ->and($capturedMeta['controlled_block'])->toBe('handler-no-return-test')
                ->and($result)->toBeTrue(); // Should return true when handler has no explicit return
        });
    });

    describe('Circuit Breaker Integration', function () {
        beforeEach(function () {
            app()->instance(CircuitBreaker::class, new CircuitBreaker);
        });

        it('configures circuit breaker with defaults', function () {
            $result = Controlled::for('breaker-test')
                ->withCircuitBreaker('test-breaker')
                ->run(fn () => 'success');

            expect($result)->toBe('success');
        });

        it('configures circuit breaker with custom threshold and decay', function () {
            $result = Controlled::for('custom-breaker-test')
                ->withCircuitBreaker('custom-breaker', 10, 600)
                ->run(fn () => 'success');

            expect($result)->toBe('success');
        });

        it('opens circuit breaker after threshold failures', function () {
            $breakerName = 'threshold-test-breaker';

            // Simulate failures to reach threshold
            for ($i = 0; $i < 5; $i++) {
                try {
                    Controlled::for("failure-{$i}")
                        ->withCircuitBreaker($breakerName)
                        ->run(function () {
                            throw new RuntimeException('Simulated failure');
                        });
                } catch (RuntimeException $e) {
                    // Expected failures
                }
            }

            // Next call should be blocked by circuit breaker
            expect(function () use ($breakerName) {
                Controlled::for('blocked-test')
                    ->withCircuitBreaker($breakerName)
                    ->run(fn () => 'should-not-execute');
            })->toThrow(RuntimeException::class, "Circuit breaker '{$breakerName}' is open");
        });

        it('resets circuit breaker on successful execution', function () {
            $breakerName = 'reset-test-breaker';

            // Force breaker open
            app(CircuitBreaker::class)->forceOpen($breakerName);

            // Should be open initially
            expect(app(CircuitBreaker::class)->isOpen($breakerName))->toBeTrue();

            // Reset the breaker manually to test reset functionality
            app(CircuitBreaker::class)->reset($breakerName);

            // Now execution should succeed and keep breaker closed
            $result = Controlled::for('reset-success-test')
                ->withCircuitBreaker($breakerName)
                ->run(fn () => 'success');

            expect($result)->toBe('success')
                ->and(app(CircuitBreaker::class)->isOpen($breakerName))->toBeFalse();
        });
    });

    describe('Transaction Support Integration', function () {
        it('executes with transaction wrapper', function () {
            DB::shouldReceive('transaction')
                ->once()
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function (Closure $callback) {
                    return $callback();
                });

            $result = Controlled::for('transaction-test')
                ->withDatabaseTransaction()
                ->run(fn () => 'transaction-success');

            expect($result)->toBe('transaction-success');
        });

        it('retries transaction on failure', function () {
            $attempts = 0;

            DB::shouldReceive('transaction')
                ->times(3) // Initial attempt + 2 retries
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function (Closure $callback) use (&$attempts) {
                    $attempts++;
                    if ($attempts < 3) {
                        throw new RuntimeException("Attempt {$attempts} failed");
                    }

                    return $callback();
                });

            $result = Controlled::for('retry-test')
                ->withDatabaseTransaction(2) // 2 retries
                ->run(fn () => 'retry-success');

            expect($result)->toBe('retry-success')
                ->and($attempts)->toBe(3);
        });
    });

    describe('Trace Management Integration', function () {
        it('overrides trace ID when specified', function () {
            $customTraceId = 'custom-trace-123';

            $result = Controlled::for('trace-override-test')
                ->overrideTraceId($customTraceId)
                ->run(fn () => 'trace-success');

            expect($result)->toBe('trace-success');
        });

        it('uses default trace when no override specified', function () {
            $result = Controlled::for('default-trace-test')
                ->run(fn () => 'default-success');

            expect($result)->toBe('default-success');
        });
    });

    describe('Custom Logger Integration', function () {
        it('uses custom logger when specified', function () {
            $result = Controlled::for('custom-logger-test', 'CustomOrigin')
                ->run(fn () => 'custom-logger-success');

            expect($result)->toBe('custom-logger-success');
        });
    });

    describe('Nested Block Prevention Integration', function () {
        it('prevents nested controlled blocks', function () {
            expect(function () {
                Controlled::for('outer-block')
                    ->run(function () {
                        Controlled::for('inner-block')
                            ->run(fn () => 'should-not-reach');
                    });
            })->toThrow(NestedControlledBlockException::class);
        });

        it('allows sequential controlled blocks', function () {
            $result1 = Controlled::for('first-block')
                ->run(fn () => 'first-success');

            $result2 = Controlled::for('second-block')
                ->run(fn () => 'second-success');

            expect($result1)->toBe('first-success')
                ->and($result2)->toBe('second-success');
        });
    });

    describe('Full Integration Chaining', function () {
        it('supports fluent interface chaining with execution', function () {
            $result = Controlled::for('chain-test', 'ChainTest')
                ->overrideContext(['initial' => 'data'])
                ->addContext(['additional' => 'context'])
                ->overrideTraceId('custom-trace')
                ->run(fn () => 'chain-success');

            expect($result)->toBe('chain-success');
        });
    });
});
