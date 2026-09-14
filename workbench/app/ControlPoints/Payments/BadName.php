<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Payments;

use Kirschbaum\Monitor\Attributes\Point;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\ControlPoint;

/**
 * Deliberately wrong: the name does not match the pattern.
 */
#[Point('BadName')]
final class BadName extends ControlPoint
{
    protected function control(Control $control): void
    {
        $control->escalate(fn (): null => null);
    }

    public function handle(): bool
    {
        return true;
    }
}
