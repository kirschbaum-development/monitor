<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Contracts;

use Kirschbaum\Monitor\Outcome;
use Throwable;

/**
 * A correction as a class, so it can be injected, reused and named in the
 * inventory. Registered with recover(SomeException::class, TheCorrection::class)
 * and resolved from the container when the risk occurs.
 */
interface Correction
{
    /**
     * Turn the failure into the value the point returns.
     */
    public function __invoke(Throwable $exception, Outcome $outcome): mixed;
}
