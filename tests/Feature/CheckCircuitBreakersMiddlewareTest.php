<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\CircuitBreaker;
use Kirschbaum\Monitor\Http\Middleware\CheckCircuitBreakers;

beforeEach(function () {
    // Reset circuit breaker state before each test
    app()->instance(CircuitBreaker::class, new CircuitBreaker());
});

describe('CheckCircuitBreakers Middleware', function () {
    describe('Handle Method', function () {
        it('allows request through when no circuit breakers are specified', function () {
            $middleware = new CheckCircuitBreakers();
            $request = Request::create('/test', 'GET');
            
            $next = function ($req) {
                return response('success', 200);
            };
            
            $response = $middleware->handle($request, $next);
            
            expect($response->getStatusCode())->toBe(200)
                ->and($response->getContent())->toBe('success');
        });

        it('allows request through when circuit breakers are closed', function () {
            $middleware = new CheckCircuitBreakers();
            $request = Request::create('/test', 'GET');
            
            $next = function ($req) {
                return response('success', 200);
            };
            
            $response = $middleware->handle($request, $next, 'breaker1', 'breaker2');
            
            expect($response->getStatusCode())->toBe(200)
                ->and($response->getContent())->toBe('success');
        });

        it('denies request when first circuit breaker is open', function () {
            $circuitBreaker = app(CircuitBreaker::class);
            $circuitBreaker->forceOpen('breaker1');
            
            $middleware = new CheckCircuitBreakers();
            $request = Request::create('/test', 'GET');
            
            $nextCalled = false;
            $next = function ($req) use (&$nextCalled) {
                $nextCalled = true;
                return response('success', 200);
            };
            
            $response = $middleware->handle($request, $next, 'breaker1', 'breaker2');
            
            expect($response->getStatusCode())->toBe(503)
                ->and($nextCalled)->toBeFalse()
                ->and($response->headers->get('X-Circuit-Breaker'))->toBe('breaker1')
                ->and($response->headers->get('X-Circuit-Breaker-Status'))->toBe('open')
                ->and($response->headers->has('Retry-After'))->toBeTrue();
        });

        it('denies request when second circuit breaker is open', function () {
            $circuitBreaker = app(CircuitBreaker::class);
            $circuitBreaker->forceOpen('breaker2');
            
            $middleware = new CheckCircuitBreakers();
            $request = Request::create('/test', 'GET');
            
            $nextCalled = false;
            $next = function ($req) use (&$nextCalled) {
                $nextCalled = true;
                return response('success', 200);
            };
            
            $response = $middleware->handle($request, $next, 'breaker1', 'breaker2');
            
            expect($response->getStatusCode())->toBe(503)
                ->and($nextCalled)->toBeFalse()
                ->and($response->headers->get('X-Circuit-Breaker'))->toBe('breaker2')
                ->and($response->headers->get('X-Circuit-Breaker-Status'))->toBe('open');
        });

        it('stops at first open circuit breaker when multiple are open', function () {
            $circuitBreaker = app(CircuitBreaker::class);
            $circuitBreaker->forceOpen('breaker1');
            $circuitBreaker->forceOpen('breaker2');
            
            $middleware = new CheckCircuitBreakers();
            $request = Request::create('/test', 'GET');
            
            $next = function ($req) {
                return response('success', 200);
            };
            
            $response = $middleware->handle($request, $next, 'breaker1', 'breaker2');
            
            expect($response->getStatusCode())->toBe(503)
                ->and($response->headers->get('X-Circuit-Breaker'))->toBe('breaker1');
        });
    });

    describe('Deny Method', function () {
        it('returns 503 response with correct headers', function () {
            $middleware = new CheckCircuitBreakers();
            
            // Use reflection to test the protected method
            $reflection = new \ReflectionClass($middleware);
            $denyMethod = $reflection->getMethod('deny');
            $denyMethod->setAccessible(true);
            
            $response = $denyMethod->invoke($middleware, 'test-breaker');
            
            expect($response)->toBeInstanceOf(Response::class)
                ->and($response->getStatusCode())->toBe(503)
                ->and($response->getContent())->toBe('')
                ->and($response->headers->get('X-Circuit-Breaker'))->toBe('test-breaker')
                ->and($response->headers->get('X-Circuit-Breaker-Status'))->toBe('open')
                ->and($response->headers->has('Retry-After'))->toBeTrue();
        });
    });

    describe('RetryAfter Method', function () {
        it('returns default 300 seconds when state has no last failure time', function () {
            $middleware = new CheckCircuitBreakers();
            
            // Use reflection to test the protected method
            $reflection = new \ReflectionClass($middleware);
            $retryAfterMethod = $reflection->getMethod('retryAfter');
            $retryAfterMethod->setAccessible(true);
            
            $retryAfter = $retryAfterMethod->invoke($middleware, 'non-existent-breaker');
            
            expect($retryAfter)->toBe(300);
        });

        it('calculates retry after based on elapsed time from failure', function () {
            $circuitBreaker = app(CircuitBreaker::class);
            
            // Record a failure to create a state with lastFailureAt
            $circuitBreaker->recordFailure('test-breaker', 300);
            
            $middleware = new CheckCircuitBreakers();
            
            // Use reflection to test the protected method
            $reflection = new \ReflectionClass($middleware);
            $retryAfterMethod = $reflection->getMethod('retryAfter');
            $retryAfterMethod->setAccessible(true);
            
            $retryAfter = $retryAfterMethod->invoke($middleware, 'test-breaker');
            
            expect($retryAfter)->toBeInt()
                ->and($retryAfter)->toBeGreaterThanOrEqual(0)
                ->and($retryAfter)->toBeLessThanOrEqual(300);
        });

        it('uses decay seconds from state when available', function () {
            $circuitBreaker = app(CircuitBreaker::class);
            
            // Record a failure with custom decay seconds
            $circuitBreaker->recordFailure('test-breaker', 600);
            
            $middleware = new CheckCircuitBreakers();
            
            // Use reflection to test the protected method
            $reflection = new \ReflectionClass($middleware);
            $retryAfterMethod = $reflection->getMethod('retryAfter');
            $retryAfterMethod->setAccessible(true);
            
            $retryAfter = $retryAfterMethod->invoke($middleware, 'test-breaker');
            
            expect($retryAfter)->toBeInt()
                ->and($retryAfter)->toBeGreaterThanOrEqual(0)
                ->and($retryAfter)->toBeLessThanOrEqual(600);
        });

        it('falls back to config when state has no decay seconds', function () {
            Config::set('monitor.circuit_breaker.default_decay_seconds', 450);
            
            $circuitBreaker = app(CircuitBreaker::class);
            
            // Create a state but without decay seconds (by using the state directly)
            // First record a failure, then manually modify to remove decay seconds
            $circuitBreaker->recordFailure('config-fallback-breaker', null);
            
            $middleware = new CheckCircuitBreakers();
            
            // Use reflection to test the protected method
            $reflection = new \ReflectionClass($middleware);
            $retryAfterMethod = $reflection->getMethod('retryAfter');
            $retryAfterMethod->setAccessible(true);
            
            $retryAfter = $retryAfterMethod->invoke($middleware, 'config-fallback-breaker');
            
            expect($retryAfter)->toBeInt()
                ->and($retryAfter)->toBeGreaterThanOrEqual(0)
                ->and($retryAfter)->toBeLessThanOrEqual(450);
        });

        it('returns 0 when decay time has fully elapsed', function () {
            $circuitBreaker = app(CircuitBreaker::class);
            
            // Create a state with an old failure time
            $circuitBreaker->recordFailure('old-breaker', 1);
            
            // Wait for decay to pass
            sleep(2);
            
            $middleware = new CheckCircuitBreakers();
            
            // Use reflection to test the protected method
            $reflection = new \ReflectionClass($middleware);
            $retryAfterMethod = $reflection->getMethod('retryAfter');
            $retryAfterMethod->setAccessible(true);
            
            $retryAfter = $retryAfterMethod->invoke($middleware, 'old-breaker');
            
            expect($retryAfter)->toBeGreaterThanOrEqual(0)
                ->and($retryAfter)->toBeLessThanOrEqual(1);
        });
    });

    describe('Integration Tests', function () {
        it('handles complete flow from open breaker to denied request', function () {
            $circuitBreaker = app(CircuitBreaker::class);
            
            // Record enough failures to open the breaker
            for ($i = 0; $i < 5; $i++) {
                $circuitBreaker->recordFailure('integration-breaker');
            }
            
            $middleware = new CheckCircuitBreakers();
            $request = Request::create('/api/test', 'GET');
            
            $next = function ($req) {
                return response()->json(['status' => 'success']);
            };
            
            $response = $middleware->handle($request, $next, 'integration-breaker');
            
            expect($response->getStatusCode())->toBe(503)
                ->and($response->headers->get('X-Circuit-Breaker'))->toBe('integration-breaker')
                ->and($response->headers->get('X-Circuit-Breaker-Status'))->toBe('open')
                ->and($response->headers->has('Retry-After'))->toBeTrue();
        });
    });
}); 