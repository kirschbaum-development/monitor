<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Filings;

/**
 * Deliberately wrong: an operation in a critical namespace with no control point.
 */
final class Uncontrolled
{
    public function submit(): string
    {
        return 'filed without anyone knowing';
    }
}
