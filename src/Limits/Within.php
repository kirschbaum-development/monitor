<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Limits;

/**
 * The whole run, attempts included, should finish inside this many seconds.
 *
 * A breach is recorded and never fails the run: a completed side effect is not
 * undone because it was slow, and PHP cannot pre-empt a running call.
 */
final readonly class Within implements Limit
{
    public function __construct(public float $seconds) {}

    public function thresholdMs(): float
    {
        return $this->seconds * 1000;
    }

    public function breachedBy(float $durationMs): bool
    {
        return $durationMs > $this->thresholdMs();
    }

    public function describe(): array
    {
        return ['type' => 'within', 'seconds' => $this->seconds];
    }
}
