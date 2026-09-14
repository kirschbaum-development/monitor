<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Breaker;

enum State: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
