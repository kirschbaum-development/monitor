<?php

declare(strict_types=1);

namespace Workbench\Monitor\ControlPoints\Filings;

/**
 * Deliberately wrong: the class name does not match the file, so the
 * autoloader cannot load it and the inventory cannot inspect it.
 */
final class GhostFiling
{
    public function submit(): string
    {
        return 'unloadable';
    }
}
