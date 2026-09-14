<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Breaker;

use Illuminate\Support\Facades\Config;

/**
 * "after" failures "within" seconds open the circuit "for" seconds.
 */
final readonly class BreakerConfig
{
    public function __construct(
        public int $after,
        public int $within,
        public int $for,
    ) {}

    public static function defaults(): self
    {
        return new self(
            after: max(1, Config::integer('monitor.breakers.after', 5)),
            within: max(1, Config::integer('monitor.breakers.within', 60)),
            for: max(1, Config::integer('monitor.breakers.for', 120)),
        );
    }

    public function with(?int $after = null, ?int $within = null, ?int $for = null): self
    {
        return new self(
            after: $after ?? $this->after,
            within: $within ?? $this->within,
            for: $for ?? $this->for,
        );
    }
}
