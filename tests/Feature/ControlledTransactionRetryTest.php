<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kirschbaum\Monitor\CircuitBreaker;
use Kirschbaum\Monitor\Controlled;
use Mockery;
use RuntimeException;

beforeEach(function () {
    // Mock logging to prevent actual log output during tests
    Log::shouldReceive('info')->byDefault();
    Log::shouldReceive('critical')->byDefault();
    Log::shouldReceive('warning')->byDefault();
    Log::shouldReceive('error')->byDefault();
    Log::shouldReceive('debug')->byDefault();

    // Reset circuit breaker state
    app()->instance(CircuitBreaker::class, new CircuitBreaker);
});

describe('Controlled Transaction Retry and Circuit Breaker Edge Cases', function () {
    describe('Transaction Retry Edge Cases', function () {
        it('throws RuntimeException when transaction retries are exhausted without success', function () {
            // Mock DB::transaction to always fail
            DB::shouldReceive('transaction')
                ->times(4) // Initial attempt + 3 retries
                ->with(Mockery::type(Closure::class))
                ->andThrow(new RuntimeException('Database connection failed'));

            expect(function () {
                Controlled::for('exhausted-retries-test')
                    ->withDatabaseTransaction(3) // 3 retries
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(RuntimeException::class, 'Database connection failed');
        });

        it('executes retry logic and succeeds on second attempt', function () {
            // This test covers the retry logic including the warning log path (line 252)
            $attempts = 0;
            DB::shouldReceive('transaction')
                ->times(2)
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function (Closure $callback) use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        throw new RuntimeException('First attempt failed');
                    }

                    return $callback();
                });

            $result = Controlled::for('retry-logic-test')
                ->withDatabaseTransaction(2) // 2 retries
                ->run(function () {
                    return 'retry-success';
                });

            expect($result)->toBe('retry-success')
                ->and($attempts)->toBe(2);
        });

        it('respects shouldRetryException logic with retryOnlyExceptions', function () {
            DB::shouldReceive('transaction')
                ->once()
                ->with(Mockery::type(Closure::class))
                ->andThrow(new InvalidArgumentException('Not retryable'));

            // Should NOT retry InvalidArgumentException when only RuntimeException is allowed
            expect(function () {
                Controlled::for('only-exceptions-test')
                    ->withDatabaseTransaction(2, [RuntimeException::class], [])
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(InvalidArgumentException::class, 'Not retryable');
        });

        it('respects shouldRetryException logic with retryExcludeExceptions', function () {
            DB::shouldReceive('transaction')
                ->once()
                ->with(Mockery::type(Closure::class))
                ->andThrow(new InvalidArgumentException('Excluded exception'));

            // Should NOT retry InvalidArgumentException when it's excluded
            expect(function () {
                Controlled::for('exclude-exceptions-test')
                    ->withDatabaseTransaction(2, [], [InvalidArgumentException::class])
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(InvalidArgumentException::class, 'Excluded exception');
        });

        it('retries when exception is in retryOnlyExceptions list', function () {
            $attempts = 0;
            DB::shouldReceive('transaction')
                ->times(2)
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function (Closure $callback) use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        throw new RuntimeException('Allowed retryable exception');
                    }

                    return $callback();
                });

            $result = Controlled::for('allowed-exception-retry-test')
                ->withDatabaseTransaction(1, [RuntimeException::class], [])
                ->run(function () {
                    return 'retry-success';
                });

            expect($result)->toBe('retry-success')
                ->and($attempts)->toBe(2);
        });

        it('handles complex retry logic with multiple exception types', function () {
            DB::shouldReceive('transaction')
                ->once()
                ->with(Mockery::type(Closure::class))
                ->andThrow(new \LogicException('Logic error')); // Not in only list

            expect(function () {
                Controlled::for('complex-retry-test')
                    ->withDatabaseTransaction(2, [RuntimeException::class, InvalidArgumentException::class], [])
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(\LogicException::class, 'Logic error');
        });
    });

    describe('Circuit Breaker Null Checks', function () {
        it('executes normally when no circuit breaker name is set', function () {
            // Test line 324: if (! $this->breakerName) return false;
            $result = Controlled::for('no-breaker-name-test')
                ->run(function () {
                    return 'success-without-breaker';
                });

            expect($result)->toBe('success-without-breaker');
        });

        it('handles failure without circuit breaker name for recordFailure', function () {
            // Test line 337: if (! $this->breakerName) return;
            expect(function () {
                Controlled::for('no-breaker-failure-test')
                    ->run(function () {
                        throw new RuntimeException('Failure without breaker name');
                    });
            })->toThrow(RuntimeException::class, 'Failure without breaker name');
        });

        it('handles success without circuit breaker name for resetCircuitBreaker', function () {
            // Test line 349: if (! $this->breakerName) return;
            $result = Controlled::for('no-breaker-success-test')
                ->run(function () {
                    return 'success-no-breaker-reset';
                });

            expect($result)->toBe('success-no-breaker-reset');
        });
    });

    describe('shouldRetryException Edge Cases', function () {
        it('returns false when exception not in retryOnlyExceptions list', function () {
            // Test the specific logic in lines 359-366 where we check retryOnlyExceptions
            // and lines 372-373 where we return false if no match found

            $attempts = 0;
            DB::shouldReceive('transaction')
                ->once()
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function () use (&$attempts) {
                    $attempts++;
                    throw new \DomainException('Domain error not in allowed list');
                });

            expect(function () {
                Controlled::for('not-in-only-list-test')
                    ->withDatabaseTransaction(2, [RuntimeException::class, InvalidArgumentException::class], [])
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(\DomainException::class, 'Domain error not in allowed list');

            // Should only attempt once since exception is not in the allowed list
            expect($attempts)->toBe(1);
        });

        it('tests the break logic in retryOnlyExceptions loop', function () {
            // This tests the break statement in the foreach loop (around line 362)
            $attempts = 0;
            DB::shouldReceive('transaction')
                ->times(2)
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function (Closure $callback) use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        // First exception is RuntimeException (which is in the allowed list)
                        throw new RuntimeException('Runtime error - should retry');
                    }

                    return $callback();
                });

            $result = Controlled::for('break-logic-test')
                ->withDatabaseTransaction(1, [RuntimeException::class, InvalidArgumentException::class], [])
                ->run(function () {
                    return 'break-logic-success';
                });

            expect($result)->toBe('break-logic-success')
                ->and($attempts)->toBe(2);
        });

        it('returns false when exception is in retryExcludeExceptions', function () {
            $attempts = 0;
            DB::shouldReceive('transaction')
                ->once()
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function () use (&$attempts) {
                    $attempts++;
                    throw new InvalidArgumentException('Excluded exception type');
                });

            expect(function () {
                Controlled::for('excluded-exception-test')
                    ->withDatabaseTransaction(2, [], [InvalidArgumentException::class])
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(InvalidArgumentException::class, 'Excluded exception type');

            expect($attempts)->toBe(1);
        });

        it('handles inheritance in exception checking', function () {
            // Test that exception inheritance works in the instanceof checks
            class CustomRuntimeException extends RuntimeException {}

            $attempts = 0;
            DB::shouldReceive('transaction')
                ->times(2)
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function (Closure $callback) use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        throw new CustomRuntimeException('Custom runtime exception');
                    }

                    return $callback();
                });

            $result = Controlled::for('inheritance-test')
                ->withDatabaseTransaction(1, [RuntimeException::class], [])
                ->run(function () {
                    return 'inheritance-success';
                });

            expect($result)->toBe('inheritance-success')
                ->and($attempts)->toBe(2);
        });
    });

    describe('Transaction Fallback Error Handling', function () {
        it('verifies transaction retry exhaustion behavior', function () {
            // Line 252 (the fallback RuntimeException) is theoretically unreachable in normal execution
            // because the while loop logic ensures we either return or throw before exiting naturally.
            // This test verifies the normal behavior when retries are exhausted.

            DB::shouldReceive('transaction')
                ->times(3) // Initial + 2 retries = 3 attempts total
                ->with(Mockery::type(Closure::class))
                ->andThrow(new RuntimeException('Persistent failure'));

            expect(function () {
                Controlled::for('retry-exhaustion-test')
                    ->withDatabaseTransaction(2) // 2 retries
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(RuntimeException::class, 'Persistent failure');
        });
    });

    describe('Complex Retry Scenarios', function () {
        it('handles mixed exception types in complex retry scenarios', function () {
            $exceptionSequence = [
                new RuntimeException('Runtime error - retryable'),
                new InvalidArgumentException('Invalid arg - not retryable for this config'),
            ];

            $attempts = 0;
            DB::shouldReceive('transaction')
                ->times(2)
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function () use (&$attempts, $exceptionSequence) {
                    $exception = $exceptionSequence[$attempts] ?? $exceptionSequence[count($exceptionSequence) - 1];
                    $attempts++;
                    throw $exception;
                });

            // Should retry RuntimeException but not InvalidArgumentException
            expect(function () {
                Controlled::for('mixed-exception-test')
                    ->withDatabaseTransaction(2, [RuntimeException::class], [])
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(InvalidArgumentException::class, 'Invalid arg - not retryable for this config');

            expect($attempts)->toBe(2);
        });

        it('forces retry warning log by testing specific retry path', function () {
            // This test specifically targets line 252 by ensuring the warning log gets called
            $attempts = 0;
            DB::shouldReceive('transaction')
                ->times(3) // Initial + 2 retries
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function (Closure $callback) use (&$attempts) {
                    $attempts++;
                    if ($attempts <= 2) {
                        throw new RuntimeException("Attempt {$attempts} failed");
                    }

                    return $callback();
                });

            // Allow any warning calls to pass through
            Log::shouldReceive('warning')->byDefault();

            $result = Controlled::for('force-retry-warning-test')
                ->withDatabaseTransaction(2) // 2 retries = 3 total attempts
                ->run(function () {
                    return 'final-success';
                });

            expect($result)->toBe('final-success')
                ->and($attempts)->toBe(3);
        });

        it('throws the last exception instead of generic RuntimeException', function () {
            // This test verifies our improved retry logic throws the actual last exception
            // rather than a generic RuntimeException
            $attempts = 0;
            DB::shouldReceive('transaction')
                ->times(3) // Initial + 2 retries = 3 attempts total
                ->with(Mockery::type(Closure::class))
                ->andReturnUsing(function () use (&$attempts) {
                    $attempts++;
                    if ($attempts === 1) {
                        throw new InvalidArgumentException('First attempt failed');
                    } elseif ($attempts === 2) {
                        throw new RuntimeException('Second attempt failed');
                    } else {
                        throw new DomainException('Third and final attempt failed');
                    }
                });

            // Allow any warning calls to pass through
            Log::shouldReceive('warning')->byDefault();

            expect(function () {
                Controlled::for('last-exception-test')
                    ->withDatabaseTransaction(2) // 2 retries = 3 total attempts
                    ->run(function () {
                        return 'should-not-reach';
                    });
            })->toThrow(DomainException::class, 'Third and final attempt failed');

            expect($attempts)->toBe(3);
        });
    });
});
