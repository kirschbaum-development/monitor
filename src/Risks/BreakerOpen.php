<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Risks;

/**
 * The point's circuit breaker is open, so nothing was attempted.
 */
final class BreakerOpen extends Risk
{
    public function __construct(public readonly string $breaker, public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf('Circuit breaker "%s" is open; retry in %ds.', $breaker, $retryAfterSeconds));
    }
}
