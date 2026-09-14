<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Events;

use Kirschbaum\Monitor\RunInfo;

class PointLimitBreached
{
    public function __construct(
        public RunInfo $run,
        public string $limit,
        public int|float $threshold,
        public int|float $actual,
    ) {}
}
