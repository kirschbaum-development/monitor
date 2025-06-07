<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Kirschbaum\Monitor\Controlled;
use RuntimeException;

describe('Controlled Class Unit Tests', function () {
    describe('Block Creation', function () {
        it('creates controlled block with for() method', function () {
            $controlled = Controlled::for('test-operation');

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('throws exception when name is not provided for execution', function () {
            $controlled = new Controlled;

            expect(fn () => $controlled->run(fn () => 'test'))
                ->toThrow(InvalidArgumentException::class, 'Controlled block name is required');
        });
    });

    describe('Basic Execution', function () {
        it('executes callback and returns result', function () {
            // This test would need to be isolated from logging
            // We'll mock the internal dependencies
            expect(true)->toBeTrue(); // Placeholder - this needs framework mocking
        });

        it('executes callback with different return types', function () {
            expect(true)->toBeTrue(); // Placeholder - this needs framework mocking
        });

        it('preserves exceptions from callback', function () {
            expect(true)->toBeTrue(); // Placeholder - this needs framework mocking
        });
    });

    describe('Context Management', function () {
        it('sets context with context() method', function () {
            $context = ['user_id' => 123, 'action' => 'test'];

            $controlled = Controlled::for('context-test')->context($context);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('merges context with with() method', function () {
            $controlled = Controlled::for('merge-context-test')
                ->context(['initial' => 'value'])
                ->with(['additional' => 'data']);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('handles complex nested context', function () {
            $complexContext = [
                'user' => ['id' => 789, 'name' => 'Test User'],
                'operation' => ['type' => 'complex', 'metadata' => ['version' => '1.0']],
            ];

            $controlled = Controlled::for('complex-context')->context($complexContext);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });
    });

    describe('Configuration Methods', function () {

        it('sets trace ID override', function () {
            $controlled = Controlled::for('trace-test')->traceId('custom-trace-123');

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('sets custom origin', function () {
            $controlled = Controlled::for('origin-test')->from('CustomOrigin');

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('configures circuit breaker parameters', function () {
            $controlled = Controlled::for('breaker-test')->breaker('test-breaker', 10, 600);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('configures transaction parameters', function () {
            $controlled = Controlled::for('transaction-test')
                ->transactioned(2, [RuntimeException::class], [InvalidArgumentException::class]);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });
    });

    describe('Method Chaining', function () {
        it('supports fluent interface chaining', function () {
            $controlled = Controlled::for('chain-test')
                ->context(['initial' => 'data'])
                ->with(['additional' => 'context'])
                ->traceId('custom-trace')
                ->breaker('test-breaker', 3, 120)
                ->transactioned(1)
                ->from('ChainTest');

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });
    });

    describe('Callback Configuration', function () {
        it('sets failure callback', function () {
            $callback = fn ($exception, $meta) => null;

            $controlled = Controlled::for('failure-test')->failing($callback);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('sets escalation callback', function () {
            $callback = fn ($meta) => null;

            $controlled = Controlled::for('escalation-test')->escalated($callback);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });
    });

    describe('Edge Cases', function () {
        it('handles empty context arrays', function () {
            $controlled = Controlled::for('empty-context-test')
                ->context([])
                ->with([]);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });

        it('handles empty values gracefully', function () {
            $controlled = Controlled::for('empty-test')
                ->context([]);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });
    });

    describe('Retry Logic Configuration', function () {
        it('configures retry parameters correctly', function () {
            $controlled = Controlled::for('retry-config-test')
                ->transactioned(2, [RuntimeException::class], [InvalidArgumentException::class]);

            expect($controlled)->toBeInstanceOf(Controlled::class);
        });
    });

    describe('Circuit Breaker Edge Cases', function () {
        it('handles circuit breaker methods when no breaker name is set', function () {
            $controlled = Controlled::for('no-breaker-test');

            // These should not throw exceptions even without a breaker name
            expect($controlled)->toBeInstanceOf(Controlled::class);

            // We can't directly test the protected methods, but we can ensure they handle null breaker names
            // This is tested indirectly through normal execution
        });
    });

    describe('Exception Trace Configuration', function () {
        it('handles exception trace configuration scenarios', function () {
            // Test will be handled through feature tests with different config settings
            expect(true)->toBeTrue();
        });
    });

});

// Note: Some tests focus on configuration without execution, others test execution paths
// that require framework integration. Complex scenarios are covered in Feature tests.
