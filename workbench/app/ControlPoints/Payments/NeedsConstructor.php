<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Payments;

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;

/**
 * Deliberately wrong: control() reads a constructor argument.
 */
#[Point('payment.capture')]
final class NeedsConstructor extends ControlPoint
{
    public function __construct(private readonly string $gateway) {}

    protected function control(Control $control): void
    {
        $control->breaker($this->gateway)->escalate(fn (): null => null);
    }

    public function handle(): bool
    {
        return true;
    }
}
