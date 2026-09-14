<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kirschbaum\Monitor\ControlPoint;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Kirschbaum\Monitor\Support\Domain;
use Throwable;

/**
 * Runs a control point on the queue: ChargeCard::dispatch($invoice).
 *
 * The queue keeps its own retries; the point keeps its policies, corrections
 * and records. A run refused by an open breaker releases the job for the
 * breaker's retry-after instead of failing it, so the queue does not retry
 * into a dependency that is known to be down. An escalated run fails the job
 * with the exception that escaped, so failed_jobs and the queue's own
 * failed() handling see exactly what the point saw.
 */
class RunControlPoint implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ControlPoint $point,
        public bool $releaseWhenRefused = true,
    ) {}

    public function handle(): ?Outcome
    {
        $outcome = $this->point->execute();

        if ($outcome->refused() && $this->releaseWhenRefused && $outcome->exception instanceof BreakerOpen) {
            $this->release(max(1, $outcome->exception->retryAfterSeconds));

            return $outcome;
        }

        if ($outcome->status->isFailure() && $outcome->exception instanceof Throwable) {
            throw $outcome->exception;
        }

        return $outcome;
    }

    /**
     * The point name, so the queue and Horizon show the operation rather than the wrapper.
     */
    public function displayName(): string
    {
        return $this->point::point()->name;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'monitor:'.$this->point::point()->name,
            'domain:'.Domain::resolve($this->point::class),
        ];
    }
}
