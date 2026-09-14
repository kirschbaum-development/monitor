<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Events;

use Kirschbaum\Monitor\RunInfo;

class PointStarted
{
    public function __construct(public RunInfo $run) {}
}
