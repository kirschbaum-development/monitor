<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Payments;

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;
use Workbench\Monitor\Support\CardDeclined;

/**
 * Deliberately incomplete: no escalation.
 */
#[Point('payment.refund')]
final class RefundCard extends ControlPoint
{
    protected function control(Control $control): void
    {
        $control->recover(CardDeclined::class, fn (): null => null);
    }

    public function handle(): string
    {
        return 'refunded';
    }
}
