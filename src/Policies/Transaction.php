<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Policies;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Kirschbaum\Monitor\Contracts\Policy;
use Kirschbaum\Monitor\Policies\Concerns\FiltersExceptions;
use Kirschbaum\Monitor\Run;

/**
 * Run the operation inside a database transaction and, when it fails with a
 * retryable exception, roll back and try the whole transaction again.
 *
 * Deadlocks are retried by default. Anything else is not, because most failures
 * inside a transaction are not made better by repeating them.
 */
class Transaction implements Policy
{
    use FiltersExceptions;

    private readonly int $retries;

    private ?string $connection = null;

    private int $backoffMs = 0;

    final public function __construct(int $retries = 0)
    {
        $this->retries = max(0, $retries);
        $this->only = [DeadlockException::class];
    }

    public static function retries(int $retries): static
    {
        return new static($retries);
    }

    public function connection(?string $name): self
    {
        $this->connection = $name;

        return $this;
    }

    public function backoff(int $ms): self
    {
        $this->backoffMs = max(0, $ms);

        return $this;
    }

    public function around(Run $run, Closure $next): mixed
    {
        $retry = Retry::times($this->retries)
            ->backoff($this->backoffMs, 1.0, true)
            ->on($this->only)
            ->except($this->except);

        return $retry->around($run, fn (): mixed => DB::connection($this->connection)->transaction(
            fn (): mixed => $next(),
        ));
    }

    public function order(): int
    {
        return Policy::ORDER_TRANSACTION;
    }

    public function describe(): array
    {
        return [
            'type' => 'transaction',
            'retries' => $this->retries,
            'connection' => $this->connection,
            'backoff_ms' => $this->backoffMs,
            'on' => $this->only,
            'except' => $this->except,
        ];
    }
}
