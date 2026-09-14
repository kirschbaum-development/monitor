<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Events;

use Kirschbaum\Monitor\Outcome;

/**
 * An escalation was due but skipped because one for the same point fired
 * inside the throttle window.
 */
final readonly class EscalationThrottled
{
    public function __construct(public Outcome $outcome, public int $seconds) {}
}
