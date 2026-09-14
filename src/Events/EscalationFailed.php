<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Events;

use Kirschbaum\Monitor\Outcome;
use Throwable;

/**
 * The escalation handler itself threw. The original exception still propagates.
 */
class EscalationFailed
{
    public function __construct(public Outcome $outcome, public Throwable $exception) {}
}
