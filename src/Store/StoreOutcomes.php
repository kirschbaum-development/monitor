<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Store;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Events\PointEnded;
use Kirschbaum\Monitor\Outcome;
use Throwable;

/**
 * Buffer outcomes and write them after the response is sent, the command has
 * finished, or the job has completed. A failing write is logged once per
 * process and never rethrown: the store is never the reason a point fails.
 */
class StoreOutcomes
{
    /**
     * How many outcomes to hold before writing regardless of lifecycle, so a
     * long-running process never keeps an unbounded buffer.
     */
    public const BUFFER_LIMIT = 100;

    /** @var list<Outcome> */
    protected array $buffer = [];

    protected bool $failureReported = false;

    public function __construct(protected OutcomeStore $store) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            PointEnded::class => 'buffer',
            JobProcessed::class => 'flush',
            JobExceptionOccurred::class => 'flush',
            Looping::class => 'flush',
            WorkerStopping::class => 'flush',
        ];
    }

    /**
     * Hold an outcome until the next flush.
     */
    public function buffer(PointEnded $event): void
    {
        if (! $this->store->enabled()) {
            return;
        }

        $this->buffer[] = $event->outcome;

        if (count($this->buffer) >= self::BUFFER_LIMIT) {
            $this->flush();
        }
    }

    /**
     * Write everything held so far and return how many rows were written.
     */
    public function flush(): int
    {
        if ($this->buffer === []) {
            return 0;
        }

        $outcomes = $this->buffer;
        $this->buffer = [];

        try {
            return $this->store->write($outcomes);
        } catch (Throwable $e) {
            if (! $this->failureReported) {
                $this->failureReported = true;
                Log::warning('[Monitor] the outcome store could not be written; outcomes are still in the log', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }

            return 0;
        }
    }

    /**
     * How many outcomes are waiting to be written.
     */
    public function pending(): int
    {
        return count($this->buffer);
    }
}
