<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Kirschbaum\Monitor\Data\CircuitBreakerState;

describe('CircuitBreakerState', function () {
    describe('Constructor Validation', function () {
        it('throws exception when failures is negative', function () {
            expect(fn () => new CircuitBreakerState(failures: -1))
                ->toThrow(InvalidArgumentException::class, 'Failure count cannot be negative.');
        });

        it('throws exception when lastFailureAt is negative', function () {
            expect(fn () => new CircuitBreakerState(lastFailureAt: -1))
                ->toThrow(InvalidArgumentException::class, 'Last failure timestamp cannot be negative.');
        });

        it('throws exception when decaySeconds is less than 1', function () {
            expect(fn () => new CircuitBreakerState(decaySeconds: 0))
                ->toThrow(InvalidArgumentException::class, 'Decay seconds must be at least 1.');
        });

        it('allows valid parameters', function () {
            $state = new CircuitBreakerState(
                failures: 5,
                lastFailureAt: time(),
                decaySeconds: 300
            );

            expect($state)->toBeInstanceOf(CircuitBreakerState::class)
                ->and($state->failures)->toBe(5)
                ->and($state->decaySeconds)->toBe(300);
        });

        it('allows null decaySeconds', function () {
            $state = new CircuitBreakerState(decaySeconds: null);

            expect($state->decaySeconds)->toBeNull();
        });
    });

    describe('hasExpired Method', function () {
        it('returns false when decaySeconds is zero', function () {
            $state = new CircuitBreakerState(failures: 1, lastFailureAt: time() - 100);

            expect($state->hasExpired(0))->toBeFalse();
        });

        it('returns false when decaySeconds is negative', function () {
            $state = new CircuitBreakerState(failures: 1, lastFailureAt: time() - 100);

            expect($state->hasExpired(-10))->toBeFalse();
        });

        it('returns true when lastFailureAt is zero', function () {
            $state = new CircuitBreakerState(failures: 1, lastFailureAt: 0);

            expect($state->hasExpired(300))->toBeTrue();
        });

        it('returns true when enough time has elapsed', function () {
            $state = new CircuitBreakerState(failures: 1, lastFailureAt: time() - 400);

            expect($state->hasExpired(300))->toBeTrue();
        });

        it('returns false when not enough time has elapsed', function () {
            $state = new CircuitBreakerState(failures: 1, lastFailureAt: time() - 100);

            expect($state->hasExpired(300))->toBeFalse();
        });
    });

    describe('Static Factory Methods', function () {
        it('creates healthy state with decaySeconds', function () {
            $state = CircuitBreakerState::healthy(300);

            expect($state->failures)->toBe(0)
                ->and($state->lastFailureAt)->toBe(0)
                ->and($state->decaySeconds)->toBe(300)
                ->and($state->isHealthy())->toBeTrue();
        });

        it('creates healthy state without decaySeconds', function () {
            $state = CircuitBreakerState::healthy();

            expect($state->failures)->toBe(0)
                ->and($state->lastFailureAt)->toBe(0)
                ->and($state->decaySeconds)->toBeNull()
                ->and($state->isHealthy())->toBeTrue();
        });

        it('creates failed state with threshold', function () {
            $beforeTime = time();
            $state = CircuitBreakerState::failed(5, 300);
            $afterTime = time();

            expect($state->failures)->toBe(5)
                ->and($state->lastFailureAt)->toBeGreaterThanOrEqual($beforeTime)
                ->and($state->lastFailureAt)->toBeLessThanOrEqual($afterTime)
                ->and($state->decaySeconds)->toBe(300)
                ->and($state->isHealthy())->toBeFalse();
        });

        it('creates failed state with minimum threshold of 1', function () {
            $state = CircuitBreakerState::failed(0);

            expect($state->failures)->toBe(1);
        });

        it('creates failed state with default threshold', function () {
            $state = CircuitBreakerState::failed();

            expect($state->failures)->toBe(999);
        });
    });

    describe('fromArray Method', function () {
        it('creates state from valid array data', function () {
            $data = [
                'failures' => 3,
                'last_failure_at' => 1234567890,
                'decay_seconds' => 300,
            ];

            $state = CircuitBreakerState::fromArray($data);

            expect($state->failures)->toBe(3)
                ->and($state->lastFailureAt)->toBe(1234567890)
                ->and($state->decaySeconds)->toBe(300);
        });

        it('handles missing keys with defaults', function () {
            $data = [];

            $state = CircuitBreakerState::fromArray($data);

            expect($state->failures)->toBe(0)
                ->and($state->lastFailureAt)->toBe(0)
                ->and($state->decaySeconds)->toBeNull();
        });

        it('handles numeric string values', function () {
            $data = [
                'failures' => '5',
                'last_failure_at' => '1234567890',
                'decay_seconds' => '300',
            ];

            $state = CircuitBreakerState::fromArray($data);

            expect($state->failures)->toBe(5)
                ->and($state->lastFailureAt)->toBe(1234567890)
                ->and($state->decaySeconds)->toBe(300);
        });

        it('handles non-numeric values with defaults', function () {
            $data = [
                'failures' => 'invalid',
                'last_failure_at' => 'invalid',
                'decay_seconds' => 'invalid',
            ];

            $state = CircuitBreakerState::fromArray($data);

            expect($state->failures)->toBe(0)
                ->and($state->lastFailureAt)->toBe(0)
                ->and($state->decaySeconds)->toBeNull();
        });

        it('handles null decay_seconds', function () {
            $data = [
                'failures' => 2,
                'last_failure_at' => 1234567890,
                'decay_seconds' => null,
            ];

            $state = CircuitBreakerState::fromArray($data);

            expect($state->decaySeconds)->toBeNull();
        });

        it('handles float values by converting to int', function () {
            $data = [
                'failures' => 3.7,
                'last_failure_at' => 1234567890.5,
                'decay_seconds' => 300.9,
            ];

            $state = CircuitBreakerState::fromArray($data);

            expect($state->failures)->toBe(3)
                ->and($state->lastFailureAt)->toBe(1234567890)
                ->and($state->decaySeconds)->toBe(300);
        });
    });

    describe('extractRequiredIntegerValue Method', function () {
        it('returns integer value when present', function () {
            $data = ['key' => 42];

            // Use reflection to test private method
            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractRequiredIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'key', 0]);

            expect($result)->toBe(42);
        });

        it('converts numeric string to integer', function () {
            $data = ['key' => '42'];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractRequiredIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'key', 0]);

            expect($result)->toBe(42);
        });

        it('returns default when key is missing', function () {
            $data = [];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractRequiredIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'missing_key', 99]);

            expect($result)->toBe(99);
        });

        it('returns default when value is not numeric', function () {
            $data = ['key' => 'not_numeric'];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractRequiredIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'key', 99]);

            expect($result)->toBe(99);
        });
    });

    describe('extractOptionalIntegerValue Method', function () {
        it('returns integer value when present', function () {
            $data = ['key' => 42];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractOptionalIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'key']);

            expect($result)->toBe(42);
        });

        it('converts numeric string to integer', function () {
            $data = ['key' => '42'];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractOptionalIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'key']);

            expect($result)->toBe(42);
        });

        it('returns null when key is missing', function () {
            $data = [];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractOptionalIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'missing_key']);

            expect($result)->toBeNull();
        });

        it('returns null when value is explicitly null', function () {
            $data = ['key' => null];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractOptionalIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'key']);

            expect($result)->toBeNull();
        });

        it('returns null when value is not numeric', function () {
            $data = ['key' => 'not_numeric'];

            $reflection = new \ReflectionClass(CircuitBreakerState::class);
            $method = $reflection->getMethod('extractOptionalIntegerValue');
            $method->setAccessible(true);

            $result = $method->invokeArgs(null, [$data, 'key']);

            expect($result)->toBeNull();
        });
    });

    describe('toArray Method', function () {
        it('converts state to array format', function () {
            $state = new CircuitBreakerState(
                failures: 5,
                lastFailureAt: 1234567890,
                decaySeconds: 300
            );

            $array = $state->toArray();

            expect($array)->toBe([
                'failures' => 5,
                'last_failure_at' => 1234567890,
                'decay_seconds' => 300,
            ]);
        });

        it('converts state with null decaySeconds to array', function () {
            $state = new CircuitBreakerState(
                failures: 3,
                lastFailureAt: 1234567890,
                decaySeconds: null
            );

            $array = $state->toArray();

            expect($array)->toBe([
                'failures' => 3,
                'last_failure_at' => 1234567890,
                'decay_seconds' => null,
            ]);
        });

        it('converts default state to array', function () {
            $state = new CircuitBreakerState;

            $array = $state->toArray();

            expect($array)->toBe([
                'failures' => 0,
                'last_failure_at' => 0,
                'decay_seconds' => null,
            ]);
        });
    });

    describe('Round-trip Conversion', function () {
        it('maintains data integrity through toArray and fromArray', function () {
            $originalState = new CircuitBreakerState(
                failures: 7,
                lastFailureAt: 1234567890,
                decaySeconds: 600
            );

            $array = $originalState->toArray();
            $reconstructedState = CircuitBreakerState::fromArray($array);

            expect($reconstructedState->failures)->toBe($originalState->failures)
                ->and($reconstructedState->lastFailureAt)->toBe($originalState->lastFailureAt)
                ->and($reconstructedState->decaySeconds)->toBe($originalState->decaySeconds);
        });

        it('maintains data integrity with null decaySeconds', function () {
            $originalState = new CircuitBreakerState(
                failures: 2,
                lastFailureAt: 1234567890,
                decaySeconds: null
            );

            $array = $originalState->toArray();
            $reconstructedState = CircuitBreakerState::fromArray($array);

            expect($reconstructedState->failures)->toBe($originalState->failures)
                ->and($reconstructedState->lastFailureAt)->toBe($originalState->lastFailureAt)
                ->and($reconstructedState->decaySeconds)->toBe($originalState->decaySeconds);
        });
    });

    describe('Additional Edge Cases', function () {
        it('handles recordFailure with null decaySeconds', function () {
            $state = new CircuitBreakerState(failures: 2, decaySeconds: 300);

            $newState = $state->recordFailure(null);

            expect($newState->failures)->toBe(3)
                ->and($newState->decaySeconds)->toBe(300); // Should preserve original
        });

        it('handles recordFailure with new decaySeconds', function () {
            $state = new CircuitBreakerState(failures: 2, decaySeconds: 300);

            $newState = $state->recordFailure(600);

            expect($newState->failures)->toBe(3)
                ->and($newState->decaySeconds)->toBe(600); // Should use new value
        });

        it('handles exceedsThreshold with zero threshold', function () {
            $state = new CircuitBreakerState(failures: 0);

            expect($state->exceedsThreshold(0))->toBeFalse(); // max(1, 0) = 1, so 0 < 1
        });

        it('handles exceedsThreshold with negative threshold', function () {
            $state = new CircuitBreakerState(failures: 0);

            expect($state->exceedsThreshold(-5))->toBeFalse(); // max(1, -5) = 1, so 0 < 1
        });

        it('handles exceedsThreshold with exact threshold match', function () {
            $state = new CircuitBreakerState(failures: 5);

            expect($state->exceedsThreshold(5))->toBeTrue(); // 5 >= 5
        });

        it('handles exceedsThreshold with failures above threshold', function () {
            $state = new CircuitBreakerState(failures: 10);

            expect($state->exceedsThreshold(5))->toBeTrue(); // 10 >= 5
        });

        it('handles isHealthy with non-zero failures', function () {
            $state = new CircuitBreakerState(failures: 1);

            expect($state->isHealthy())->toBeFalse();
        });

        it('handles isHealthy with zero failures', function () {
            $state = new CircuitBreakerState(failures: 0);

            expect($state->isHealthy())->toBeTrue();
        });
    });
});
