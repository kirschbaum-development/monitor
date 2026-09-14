<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Policies;

use Closure;
use Illuminate\Support\Sleep;
use Kirschbaum\Monitor\Policies\Concerns\FiltersExceptions;
use Kirschbaum\Monitor\Run;
use Kirschbaum\Monitor\Support\ChildEscalations;
use Throwable;

/**
 * Attempt the operation again after a failure, with exponential backoff.
 *
 * The exception that ends the last attempt is the one that reaches the point's
 * corrections, so recover(DeadlockException::class, ...) matches what actually
 * happened rather than a wrapper. An attempts() limit on the point caps the
 * total regardless of what is asked for here.
 */
final class Retry implements Policy
{
    use FiltersExceptions;

    private int $times = 1;

    private int $backoffMs = 0;

    private float $multiplier = 2.0;

    private bool $jitter = true;

    public static function times(int $times): self
    {
        return (new self)->setTimes($times);
    }

    public function setTimes(int $times): self
    {
        $this->times = max(0, $times);

        return $this;
    }

    public function backoff(int $ms, float $multiplier = 2.0, bool $jitter = true): self
    {
        $this->backoffMs = max(0, $ms);
        $this->multiplier = max(1.0, $multiplier);
        $this->jitter = $jitter;

        return $this;
    }

    public function around(Run $run, Closure $next): mixed
    {
        $tries = 0;

        while (true) {
            try {
                return $next();
            } catch (Throwable $e) {
                $tries++;

                if (! $this->applies($e) || ChildEscalations::contains($e) || $tries > $this->times || $run->attempt() >= $run->maxAttempts()) {
                    throw $e;
                }

                $delay = $this->delayFor($tries);

                if ($delay > 0) {
                    Sleep::for($delay)->milliseconds();
                }

                $run->retried($e, $delay);
            }
        }
    }

    public function order(): int
    {
        return Policy::ORDER_RETRY;
    }

    public function describe(): array
    {
        return [
            'type' => 'retry',
            'times' => $this->times,
            'backoff_ms' => $this->backoffMs,
            'multiplier' => $this->multiplier,
            'jitter' => $this->jitter,
            'on' => $this->only,
            'except' => $this->except,
        ];
    }

    /**
     * The delay after the given number of failed tries, in milliseconds.
     */
    private function delayFor(int $tries): int
    {
        if ($this->backoffMs === 0) {
            return 0;
        }

        $delay = (int) round($this->backoffMs * ($this->multiplier ** ($tries - 1)));

        if ($this->jitter) {
            return random_int((int) ($delay / 2), $delay);
        }

        return $delay;
    }
}
