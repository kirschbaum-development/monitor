<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Store;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\Events\PointEnded;
use Kirschbaum\Monitor\Outcome;
use Throwable;

/**
 * Buffers outcomes and writes them after the response is sent or the job has
 * finished. A failing write is logged once per process and never rethrown:
 * the store is never the reason a control point fails.
 */
final class StoreOutcomes
{
    /** @var list<Outcome> */
    private array $buffer = [];

    private bool $flushRegistered = false;

    private bool $failureReported = false;

    public function __construct(
        private readonly OutcomeStore $store,
        private readonly Application $app,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            PointEnded::class => 'buffer',
            JobProcessed::class => 'flush',
            JobExceptionOccurred::class => 'flush',
        ];
    }

    public function buffer(PointEnded $event): void
    {
        if (! $this->store->enabled()) {
            return;
        }

        $this->buffer[] = $event->outcome;

        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            $this->app->terminating(fn (): int => $this->flush());
        }
    }

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

    public function pending(): int
    {
        return count($this->buffer);
    }
}
