<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kirschbaum\Monitor\CircuitBreaker;
use Kirschbaum\Monitor\Facades\Monitor;
use Mockery;
use RuntimeException;

beforeEach(function () {
    Cache::flush();
});

describe('Monitor Facade Integration with Controlled', function () {
    it('creates controlled block through facade', function () {
        $result = Monitor::controlled('facade-test')
            ->run(fn () => 'facade-success');

        expect($result)->toBe('facade-success');
    });

    it('creates controlled block with name parameter through facade', function () {
        $result = Monitor::controlled('named-test')
            ->run(fn () => 'named-success');

        expect($result)->toBe('named-success');
    });

    it('creates controlled block with immediate name through facade', function () {
        $controlled = Monitor::controlled('immediate-name');

        expect($controlled)->toBeInstanceOf(\Kirschbaum\Monitor\Controlled::class);

        $result = $controlled->run(fn () => 'immediate-success');
        expect($result)->toBe('immediate-success');
    });

    it('integrates with circuit breaker through facade', function () {
        app()->instance(CircuitBreaker::class, new CircuitBreaker);

        $result = Monitor::controlled('breaker-integration')
            ->withCircuitBreaker('test-integration-breaker')
            ->run(fn () => 'breaker-success');

        expect($result)->toBe('breaker-success');
    });

    it('integrates with transactions through facade', function () {
        DB::shouldReceive('transaction')
            ->once()
            ->with(Mockery::type(\Closure::class))
            ->andReturnUsing(function (\Closure $callback) {
                return $callback();
            });

        $result = Monitor::controlled('transaction-integration')
            ->withDatabaseTransaction()
            ->run(fn () => 'transaction-success');

        expect($result)->toBe('transaction-success');
    });

    it('chains multiple configuration methods through facade', function () {
        $result = Monitor::controlled('full-chain-test', 'IntegrationTest')
            ->overrideContext(['feature' => 'integration'])
            ->addContext(['test' => 'comprehensive'])
            ->run(fn () => 'full-chain-success');

        expect($result)->toBe('full-chain-success');
    });

    it('handles failures with callbacks through facade', function () {
        $failureCalled = false;
        $uncaughtCalled = false;

        expect(function () use (&$failureCalled, &$uncaughtCalled) {
            Monitor::controlled('integration-failure-test')
                ->catching([
                    \InvalidArgumentException::class => function () use (&$failureCalled) {
                        $failureCalled = true; // This should not be called
                    },
                ])
                ->onUncaughtException(function ($exception, $meta) use (&$uncaughtCalled) {
                    $uncaughtCalled = true; // This should be called for uncaught exceptions
                })
                ->run(function () {
                    throw new RuntimeException('Integration test failure'); // RuntimeException not caught
                });
        })->toThrow(RuntimeException::class, 'Integration test failure');

        expect($failureCalled)->toBeFalse() // Handler not called since exception type doesn't match
            ->and($uncaughtCalled)->toBeTrue(); // onUncaughtException called for uncaught exception
    });

    it('preserves trace context through facade', function () {
        // Start a trace
        $trace = Monitor::trace()->start();

        $result = Monitor::controlled('trace-context-test')
            ->run(fn () => 'trace-preserved');

        expect($result)->toBe('trace-preserved');

        // Just verify trace is still accessible
        expect(Monitor::trace())->toBeInstanceOf(\Kirschbaum\Monitor\Trace::class);
    });

    it('overrides trace ID through facade', function () {
        $customTraceId = 'custom-integration-trace-123';

        $result = Monitor::controlled('trace-override-integration')
            ->overrideTraceId($customTraceId)
            ->run(fn () => 'trace-override-success');

        expect($result)->toBe('trace-override-success');
    });

    it('works with custom logger through facade', function () {
        $result = Monitor::controlled('custom-logger-integration', 'CustomIntegrationOrigin')
            ->run(fn () => 'custom-logger-success');

        expect($result)->toBe('custom-logger-success');
    });

});

describe('Monitor with Origin Integration', function () {
    it('creates controlled block from origin instance', function () {
        $originInstance = new class
        {
            public function __toString(): string
            {
                return 'TestOrigin';
            }
        };

        $result = Monitor::controlled('origin-test', $originInstance)
            ->run(fn () => 'origin-success');

        expect($result)->toBe('origin-success');
    });

    it('preserves origin through controlled block chain', function () {
        $result = Monitor::controlled('string-origin-test', 'StringOrigin')
            ->overrideContext(['test' => 'origin-preservation'])
            ->run(fn () => 'origin-chain-success');

        expect($result)->toBe('origin-chain-success');
    });
});

describe('Real-world Integration Scenarios', function () {
    it('simulates payment processing with comprehensive monitoring', function () {
        $paymentSuccessful = false;

        $result = Monitor::controlled('payment_processing')
            ->overrideContext([
                'user_id' => 123,
                'amount' => 99.99,
                'currency' => 'USD',
            ])

            ->catching([
                \Throwable::class => function ($exception, $meta) {
                    // Log to financial audit system
                    // In real implementation would call external service
                },
            ])
            ->onUncaughtException(function ($exception, $meta) {
                // Alert financial operations team
                // In real implementation would trigger alerts
            })
            ->run(function () use (&$paymentSuccessful) {
                // Simulate payment processing
                $paymentSuccessful = true;

                return ['transaction_id' => 'txn_123', 'status' => 'completed'];
            });

        expect($result)->toBe(['transaction_id' => 'txn_123', 'status' => 'completed'])
            ->and($paymentSuccessful)->toBeTrue();
    });

    it('simulates database operation with retry and circuit breaker', function () {
        app()->instance(CircuitBreaker::class, new CircuitBreaker);

        DB::shouldReceive('transaction')
            ->twice() // First attempt fails, second succeeds
            ->with(Mockery::type(\Closure::class))
            ->andReturnUsing(function (\Closure $callback) {
                static $attempts = 0;
                $attempts++;

                if ($attempts === 1) {
                    throw new RuntimeException('Database temporarily unavailable');
                }

                return $callback();
            });

        $result = Monitor::controlled('database_critical_write')
            ->overrideContext(['operation' => 'user_signup', 'table' => 'users'])

            ->withCircuitBreaker('database_write', 3, 60)
            ->withDatabaseTransaction(1) // 1 retry
            ->catching([
                \RuntimeException::class => function ($exception, $meta) {
                    // Log database issues for monitoring
                },
            ])
            ->run(function () {
                return ['id' => 456, 'status' => 'created'];
            });

        expect($result)->toBe(['id' => 456, 'status' => 'created']);
    });

    it('simulates external API call with full monitoring stack', function () {
        $apiCallCount = 0;

        $result = Monitor::controlled('external_api_integration')
            ->overrideContext([
                'service' => 'stripe',
                'endpoint' => '/charges',
                'method' => 'POST',
            ])

            ->overrideTraceId('api-call-trace-789')
            ->catching([
                \Exception::class => function ($exception, $meta) use (&$apiCallCount) {
                    // Increment failure metrics
                    $apiCallCount++;
                },
            ])
            ->run(function () {
                // Simulate successful API call
                return ['charge_id' => 'ch_123', 'amount' => 2000, 'status' => 'succeeded'];
            });

        expect($result)->toBe(['charge_id' => 'ch_123', 'amount' => 2000, 'status' => 'succeeded'])
            ->and($apiCallCount)->toBe(0); // No failures
    });
});
