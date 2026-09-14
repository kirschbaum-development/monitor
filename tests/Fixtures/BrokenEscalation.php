<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Kirschbaum\Monitor\Escalations\Escalation;
use Kirschbaum\Monitor\Outcome;
use LogicException;

final class BrokenEscalation implements Escalation
{
    public function handle(Outcome $outcome): void
    {
        throw new LogicException('pager is down');
    }
}
