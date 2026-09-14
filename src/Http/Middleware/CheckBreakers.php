<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Refuse a route with 503 while any of the named circuits is open.
 *
 *     Route::post('/payments', ...)->middleware('breakers:stripe,efiling');
 *
 * Retry-After is the real time left on the circuit. Checking does not consume
 * the circuit's probe; the first control point through does that.
 */
final readonly class CheckBreakers
{
    public function __construct(private CircuitBreaker $breaker) {}

    public function handle(Request $request, Closure $next, string ...$breakers): SymfonyResponse
    {
        foreach ($breakers as $name) {
            if ($this->breaker->isOpen($name)) {
                return $this->refuse($name);
            }
        }

        /** @var SymfonyResponse $response */
        $response = $next($request);

        return $response;
    }

    private function refuse(string $name): Response
    {
        $retryAfter = max(1, $this->breaker->retryAfter($name));

        return new Response('', 503, [
            'Retry-After' => (string) $retryAfter,
            'X-Circuit-Breaker' => $name,
            'X-Circuit-Breaker-Status' => 'open',
        ]);
    }
}
