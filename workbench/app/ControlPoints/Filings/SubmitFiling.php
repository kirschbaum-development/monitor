<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Filings;

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;
use Throwable;

/**
 * Deliberately questionable: a catch-all and no escalation.
 */
#[Point('filing.submit', domain: 'Courts')]
final class SubmitFiling extends ControlPoint
{
    protected function control(Control $control): void
    {
        $control->transaction(retries: 1)->within(5)->recover(Throwable::class, fn (): string => 'queued for manual filing');
    }

    public function handle(): string
    {
        return 'filed';
    }
}
