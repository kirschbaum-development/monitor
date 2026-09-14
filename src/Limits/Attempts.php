<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Limits;

/**
 * A hard cap on attempts for the run, whatever the retry policies ask for.
 */
final readonly class Attempts implements Limit
{
    public function __construct(public int $max) {}

    public function name(): string
    {
        return 'attempts';
    }

    public function describe(): array
    {
        return ['type' => 'attempts', 'max' => $this->max];
    }
}
