<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Contracts;

use Closure;
use Kirschbaum\Monitor\Run;

/**
 * A reusable behaviour wrapped around the attempt of a control point.
 *
 * Policies form a pipeline ordered by order(), lowest outermost. The shipped
 * policies use 100 (Breaker), 200 (Retry) and 300 (Transaction), so a breaker
 * sees one failure per run and a transaction is retried whole.
 */
interface Policy
{
    public const ORDER_ONCE = 50;

    public const ORDER_BREAKER = 100;

    public const ORDER_RETRY = 200;

    public const ORDER_TRANSACTION = 300;

    /**
     * @param  Closure(): mixed  $next
     */
    public function around(Run $run, Closure $next): mixed;

    public function order(): int;

    /**
     * A static description for the inventory and the outcome. Must not depend
     * on anything only known at runtime.
     *
     * @return array<string, mixed>
     */
    public function describe(): array;
}
