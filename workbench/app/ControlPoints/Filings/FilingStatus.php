<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Filings;

/**
 * Not an operation, so exempt from the critical namespace rule.
 */
enum FilingStatus: string
{
    case Filed = 'filed';
    case Rejected = 'rejected';
}
