<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

/**
 * How a control point run ended. Every run ends in exactly one of these.
 */
enum Status: string
{
    /** The operation returned and every ensure() held. */
    case Succeeded = 'succeeded';

    /** The operation failed with a declared risk and its correction produced the value. */
    case Recovered = 'recovered';

    /** The operation failed with something no correction covered; the exception left the point. */
    case Escalated = 'escalated';

    /** The breaker was open and nothing was attempted. */
    case Refused = 'refused';

    public function isFailure(): bool
    {
        return $this === self::Escalated || $this === self::Refused;
    }
}
