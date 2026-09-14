<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Risks;

/**
 * The operation completed but its result did not satisfy an ensure() check.
 */
final class EnsureFailed extends Risk
{
    public function __construct(public readonly string $reason, public readonly mixed $value)
    {
        parent::__construct(sprintf('Result did not satisfy ensure(): %s', $reason));
    }
}
