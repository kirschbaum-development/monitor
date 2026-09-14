<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Trace;

use Kirschbaum\Monitor\ControlStack;

/**
 * A job dispatched from inside a trace carries it through Laravel's Context.
 * One dispatched from nowhere gets a trace of its own when it starts. The
 * control stack it inherited belongs to the dispatching process: it is handed
 * off, so the job starts with no parent and a dispatched_from run id instead.
 */
class PicksUpJobTrace
{
    public function __construct(protected Trace $trace, protected ControlStack $stack) {}

    public function handle(): void
    {
        $this->trace->pickup();
        $this->stack->handOff();
    }
}
