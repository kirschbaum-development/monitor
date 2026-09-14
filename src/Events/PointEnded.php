<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Events;

use Kirschbaum\Monitor\Outcome;

class PointEnded
{
    public function __construct(public Outcome $outcome) {}
}
