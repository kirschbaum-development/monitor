<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Kirschbaum\Monitor\Escalations\Escalation;
use Kirschbaum\Monitor\Outcome;

final class PagePayments implements Escalation
{
    public static ?Outcome $received = null;

    public function handle(Outcome $outcome): void
    {
        self::$received = $outcome;
    }
}
