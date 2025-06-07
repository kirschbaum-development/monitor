<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Kirschbaum\Monitor\CircuitBreaker;

it('throws exception when threshold is less than 1', function () {
    expect(fn () => new CircuitBreaker(0))
        ->toThrow(InvalidArgumentException::class, 'Threshold must be at least 1.');
});

it('throws exception when threshold is negative', function () {
    expect(fn () => new CircuitBreaker(-1))
        ->toThrow(InvalidArgumentException::class, 'Threshold must be at least 1.');
});

it('throws exception when decay seconds is less than 1', function () {
    expect(fn () => new CircuitBreaker(5, 0))
        ->toThrow(InvalidArgumentException::class, 'Decay seconds must be at least 1.');
});

it('throws exception when decay seconds is negative', function () {
    expect(fn () => new CircuitBreaker(5, -1))
        ->toThrow(InvalidArgumentException::class, 'Decay seconds must be at least 1.');
});

it('throws exception when both threshold and decay seconds are invalid', function () {
    expect(fn () => new CircuitBreaker(0, 0))
        ->toThrow(InvalidArgumentException::class, 'Threshold must be at least 1.');
});

it('creates circuit breaker successfully with valid parameters', function () {
    $circuitBreaker = new CircuitBreaker(5, 300);
    
    expect($circuitBreaker)->toBeInstanceOf(CircuitBreaker::class);
});

it('creates circuit breaker successfully with minimum valid parameters', function () {
    $circuitBreaker = new CircuitBreaker(1, 1);
    
    expect($circuitBreaker)->toBeInstanceOf(CircuitBreaker::class);
});

it('creates circuit breaker successfully with default parameters', function () {
    $circuitBreaker = new CircuitBreaker();
    
    expect($circuitBreaker)->toBeInstanceOf(CircuitBreaker::class);
});

it('returns false when circuit breaker state has expired', function () {
    $circuitBreaker = new CircuitBreaker();
    
    // Record a failure first to create a state
    $circuitBreaker->recordFailure('test_breaker', 1); // 1 second decay
    
    // Wait to ensure expiration
    sleep(2);
    
    // This should trigger the expired state path and reset the breaker
    $isOpen = $circuitBreaker->isOpen('test_breaker', 1, 1);
    
    expect($isOpen)->toBeFalse();
});

it('returns true when circuit is closed', function () {
    $circuitBreaker = new CircuitBreaker();
    
    // New circuit breaker should be closed
    expect($circuitBreaker->isClosed('test_breaker'))->toBeTrue();
});

it('returns false when circuit is open', function () {
    $circuitBreaker = new CircuitBreaker();
    
    // Force open the circuit breaker
    $circuitBreaker->forceOpen('test_breaker');
    
    expect($circuitBreaker->isClosed('test_breaker'))->toBeFalse();
});

it('returns zero failure count when no state exists', function () {
    $circuitBreaker = new CircuitBreaker();
    
    expect($circuitBreaker->getFailureCount('non_existent'))->toBe(0);
});

it('returns actual failure count when state exists', function () {
    $circuitBreaker = new CircuitBreaker();
    
    // Record multiple failures
    $circuitBreaker->recordFailure('test_breaker');
    $circuitBreaker->recordFailure('test_breaker');
    $circuitBreaker->recordFailure('test_breaker');
    
    expect($circuitBreaker->getFailureCount('test_breaker'))->toBe(3);
});

it('returns null for last failure time when no state exists', function () {
    $circuitBreaker = new CircuitBreaker();
    
    expect($circuitBreaker->getLastFailureTime('non_existent'))->toBeNull();
});

it('returns null for last failure time when state has zero timestamp', function () {
    $circuitBreaker = new CircuitBreaker();
    
    // Create a healthy state (no failures recorded)
    $circuitBreaker->recordSuccess('test_breaker');
    
    expect($circuitBreaker->getLastFailureTime('test_breaker'))->toBeNull();
});

it('returns actual timestamp for last failure time when failures exist', function () {
    $circuitBreaker = new CircuitBreaker();
    
    $beforeTime = time();
    $circuitBreaker->recordFailure('test_breaker');
    $afterTime = time();
    
    $lastFailureTime = $circuitBreaker->getLastFailureTime('test_breaker');
    
    expect($lastFailureTime)->toBeInt()
        ->and($lastFailureTime)->toBeGreaterThanOrEqual($beforeTime)
        ->and($lastFailureTime)->toBeLessThanOrEqual($afterTime);
});

it('returns true for isHealthy when no state exists', function () {
    $circuitBreaker = new CircuitBreaker();
    
    expect($circuitBreaker->isHealthy('non_existent'))->toBeTrue();
});

it('returns true for isHealthy when state exists but is healthy', function () {
    $circuitBreaker = new CircuitBreaker();
    
    // Record a failure then a success to reset to healthy state
    $circuitBreaker->recordFailure('test_breaker');
    $circuitBreaker->recordSuccess('test_breaker');
    
    expect($circuitBreaker->isHealthy('test_breaker'))->toBeTrue();
});

it('returns false for isHealthy when state has failures', function () {
    $circuitBreaker = new CircuitBreaker();
    
    $circuitBreaker->recordFailure('test_breaker');
    
    expect($circuitBreaker->isHealthy('test_breaker'))->toBeFalse();
}); 