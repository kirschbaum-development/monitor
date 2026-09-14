<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Events;

use Kirschbaum\Monitor\RunInfo;
use Throwable;

class PointRetried
{
    /**
     * @param  int  $attempt  the attempt that just failed
     * @param  int  $backoffMs  how long the run waited before the next attempt
     */
    public function __construct(
        public RunInfo $run,
        public Throwable $exception,
        public int $attempt,
        public int $backoffMs,
    ) {}
}
