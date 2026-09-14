<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Contracts;

use Kirschbaum\Monitor\Outcome;

/**
 * Who is told when a control point fails with something no correction covers.
 *
 * Resolved from the container, so constructor injection works. The exception
 * has already been recorded when handle() runs and will propagate after it
 * returns; an escalation that throws is recorded and does not replace it.
 */
interface Escalation
{
    public function handle(Outcome $outcome): void;
}
