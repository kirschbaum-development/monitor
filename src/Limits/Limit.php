<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Limits;

/**
 * A threshold a run must stay within. Whether a breach is recorded or
 * fails the run is decided by the limit, not the caller.
 */
interface Limit
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function describe(): array;
}
