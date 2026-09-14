<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Filings;

use Kirschbaum\Monitor\ControlPoint;

/**
 * Deliberately wrong: a control point class with no #[Point] attribute.
 */
final class Unnamed extends ControlPoint
{
    public function handle(): bool
    {
        return true;
    }
}
