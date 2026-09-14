<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Events;

use Kirschbaum\Monitor\Breaker\BreakerState;

final readonly class BreakerOpened
{
    public function __construct(public string $breaker, public BreakerState $state) {}
}
