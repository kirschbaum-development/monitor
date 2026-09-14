<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Limits;

use Closure;
use Kirschbaum\Monitor\Contracts\Limit;

/**
 * A post-condition on the returned value. The only limit that fails a run,
 * because it judges the result rather than the road to it. Runs after every
 * policy, so a failed ensure() is never retried.
 */
final readonly class Ensure implements Limit
{
    /**
     * @param  Closure(mixed): bool  $check
     */
    public function __construct(public Closure $check, public string $reason) {}

    public function holdsFor(mixed $value): bool
    {
        return ($this->check)($value) === true;
    }

    public function describe(): array
    {
        return ['type' => 'ensure', 'reason' => $this->reason];
    }
}
