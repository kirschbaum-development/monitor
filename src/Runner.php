<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;

/**
 * Executes a control point. The live runner builds a Run; the fake records.
 */
interface Runner
{
    /**
     * @param  Closure(): mixed  $callback
     */
    public function execute(Control $control, Closure $callback): Outcome;
}
