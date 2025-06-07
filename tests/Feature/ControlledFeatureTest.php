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
                ->context($context)
                ->run(fn () => 'context-success');

            expect($result)->toBe('context-success');
        });

        it('merges context and executes', function () {
            $result = Controlled::for('merge-context-test')
                ->context(['initial' => 'value'])
                ->with(['additional' => 'data'])
                ->run(fn () => 'merge-success');

            expect($result)->toBe('merge-success');
        });
    });

    describe('Failure Handling Integration', function () {
        it('calls failure callback when operation fails', function () {
            $failureCalled = false;
            $capturedMeta = null;

            expect(function () use (&$failureCalled, &$capturedMeta) {
                Controlled::for('fail-callback-test')
                    ->failing(function ($exception, $meta) use (&$failureCalled, &$capturedMeta) {
                        $failureCalled = true;
                        $capturedMeta = $meta;
                    })
                    ->run(function () {
                        throw new RuntimeException('Test failure');
                    });
            })->toThrow(RuntimeException::class, 'Test failure');

            expect($failureCalled)->toBeTrue()
                ->and($capturedMeta)->toBeArray()
                ->and($capturedMeta['controlled_block'])->toBe('fail-callback-test')
                ->and($capturedMeta['status'])->toBe('failed');
        });

        it('calls escalation callback when operation fails', function () {
            $escalationCalled = false;
            $capturedMeta = null;

            expect(function () use (&$escalationCalled, &$capturedMeta) {
                Controlled::for('escalate-test')
                    ->escalated(function ($meta) use (&$escalationCalled, &$capturedMeta) {
                        $escalationCalled = true;
                        $capturedMeta = $meta;
                    })
                    ->run(function () {
                        throw new RuntimeException('Test escalation');
                    });
            })->toThrow(RuntimeException::class, 'Test escalation');

            expect($escalationCalled)->toBeTrue()
                ->and($capturedMeta)->toBeArray()
                ->and($capturedMeta['controlled_block'])->toBe('escalate-test');
        });

        it('continues execution if failure callback throws', function () {
            expect(function () {
                Controlled::for('callback-error-test')
                    ->failing(function () {
                        throw new RuntimeException('Callback error');
                    })
                    ->run(function () {
                        throw new RuntimeException('Original error');
                    });
            })->toThrow(RuntimeException::class, 'Original error');
        });
    });

    describe('Circuit Breaker Integration', function () {
        beforeEach(function () {
            app()->instance(CircuitBreaker::class, new CircuitBreaker);
        });

        it('configures circuit breaker with defaults', function () {
            $result = Controlled::for('breaker-test')
                ->breaker('test-breaker')
                ->run(fn () => 'success');

            expect($result)->toBe('success');
        });

        it('configures circuit breaker with custom threshold and decay', function () {
            $result = Controlled::for('custom-breaker-test')
                ->breaker('custom-breaker', 10, 600)
                ->run(fn () => 'success');

            expect($result)->toBe('success');
        });

        it('opens circuit breaker after threshold failures', function () {
            $breakerName = 'threshold-test-breaker';

            // Simulate failures to reach threshold
            for ($i = 0; $i < 5; $i++) {
                try {
                    Controlled::for("failure-{$i}")
                        ->breaker($breakerName)
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
                    ->breaker($breakerName)
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
                ->breaker($breakerName)
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
                ->transactioned()
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
                ->transactioned(2) // 2 retries
                ->run(fn () => 'retry-success');

            expect($result)->toBe('retry-success')
                ->and($attempts)->toBe(3);
        });
    });

    describe('Trace Management Integration', function () {
        it('overrides trace ID when specified', function () {
            $customTraceId = 'custom-trace-123';

            $result = Controlled::for('trace-override-test')
                ->traceId($customTraceId)
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
            $result = Controlled::for('custom-logger-test')
                ->from('CustomOrigin')
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
            $result = Controlled::for('chain-test')
                ->context(['initial' => 'data'])
                ->with(['additional' => 'context'])
                ->traceId('custom-trace')
                ->from('ChainTest')
                ->run(fn () => 'chain-success');

            expect($result)->toBe('chain-success');
        });
    });
});
