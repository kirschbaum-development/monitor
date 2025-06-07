<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Kirschbaum\Monitor\Facades\Monitor;

class CheckCircuitBreakers
{
    public function handle(Request $request, Closure $next, string ...$breakers): Response
    {
        foreach ($breakers as $breakerName) {
            if (Monitor::breaker()->isOpen($breakerName)) {
                return $this->deny($breakerName);
            }
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    protected function deny(string $breaker): Response
    {
        $retryAfter = $this->retryAfter($breaker);

        return response('', 503)
            ->header('Retry-After', (string) $retryAfter)
            ->header('X-Circuit-Breaker', $breaker)
            ->header('X-Circuit-Breaker-Status', 'open');
    }

    protected function retryAfter(string $breaker): int
    {
        $state = Monitor::breaker()->getState($breaker);

        if (! $state?->lastFailureAt) {
            return 300;
        }

        $elapsed = time() - $state->lastFailureAt;

        // Use the decay from the breaker state if available, otherwise fall back to config
        $decay = $state->decaySeconds ?? Config::integer('monitor.circuit_breaker.default_decay_seconds', 300);

        return random_int(0, max(0, $decay - $elapsed));
    }
}
