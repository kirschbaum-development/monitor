<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Breaker;

final readonly class Decision
{
    private function __construct(
        public bool $allowed,
        public int $retryAfterSeconds,
        public bool $probe,
    ) {}

    public static function allow(): self
    {
        return new self(true, 0, false);
    }

    public static function probe(): self
    {
        return new self(true, 0, true);
    }

    public static function refuse(int $retryAfterSeconds): self
    {
        return new self(false, $retryAfterSeconds, false);
    }
}
