<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Limits;

use Kirschbaum\Monitor\Contracts\Limit;

/**
 * A hard cap on attempts for the run, whatever the retry policies ask for.
 */
final readonly class Attempts implements Limit
{
    public function __construct(public int $max) {}

    public function describe(): array
    {
        return ['type' => 'attempts', 'max' => $this->max];
    }
}
