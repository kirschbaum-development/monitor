<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Escalations;

use Kirschbaum\Monitor\Contracts\Escalation;
use Kirschbaum\Monitor\Outcome;

final class PagePayments implements Escalation
{
    /** @var list<Outcome> */
    public static array $paged = [];

    public function handle(Outcome $outcome): void
    {
        self::$paged[] = $outcome;
    }
}
