<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Trace;

/**
 * A job dispatched from inside a trace carries it through Laravel's Context.
 * One dispatched from nowhere gets a trace of its own when it starts.
 */
class PicksUpJobTrace
{
    public function __construct(private readonly Trace $trace) {}

    public function handle(): void
    {
        $this->trace->pickup();
    }
}
