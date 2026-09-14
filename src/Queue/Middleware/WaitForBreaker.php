<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Queue\Middleware;

use Closure;
use Illuminate\Container\Container;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;

/**
 * Release the job for the breaker's retry-after while a circuit is open,
 * instead of running it into a dependency that is known to be down.
 *
 *     public function middleware(): array
 *     {
 *         return [new WaitForBreaker('stripe')];
 *     }
 *
 * Every release counts as an attempt, so the job's own $tries or retryUntil()
 * still bounds how long it waits.
 */
class WaitForBreaker
{
    public function __construct(protected string $breaker, protected int $minimumDelay = 1) {}

    /**
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $circuit = Container::getInstance()->make(CircuitBreaker::class);

        if ($circuit->isOpen($this->breaker)) {
            if (method_exists($job, 'release')) {
                $job->release(max($this->minimumDelay, $circuit->retryAfter($this->breaker)));
            }

            return null;
        }

        return $next($job);
    }
}
