<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Data;

use InvalidArgumentException;

final readonly class CircuitBreakerState
{
    public function __construct(
        public int $failures = 0,
        public int $lastFailureAt = 0,
        public ?int $decaySeconds = null,
    ) {
        if ($this->failures < 0) {
            throw new InvalidArgumentException('Failure count cannot be negative.');
        }

        if ($this->lastFailureAt < 0) {
            throw new InvalidArgumentException('Last failure timestamp cannot be negative.');
        }

        if ($this->decaySeconds !== null && $this->decaySeconds < 1) {
            throw new InvalidArgumentException('Decay seconds must be at least 1.');
        }
    }

    public function recordFailure(?int $decaySeconds = null): self
    {
        return new self(
            failures: $this->failures + 1,
            lastFailureAt: time(),
            decaySeconds: $decaySeconds ?? $this->decaySeconds,
        );
    }

    public function hasExpired(int $decaySeconds): bool
    {
        if ($decaySeconds <= 0) {
            return false;
        }

        if ($this->lastFailureAt === 0) {
            return true;
        }

        return (time() - $this->lastFailureAt) >= $decaySeconds;
    }

    public function exceedsThreshold(int $threshold): bool
    {
        return $this->failures >= max(1, $threshold);
    }

    public function isHealthy(): bool
    {
        return $this->failures === 0;
    }

    /**
     * @return array{failures: int, last_failure_at: int, decay_seconds: int|null}
     */
    public function toArray(): array
    {
        return [
            'failures' => $this->failures,
            'last_failure_at' => $this->lastFailureAt,
            'decay_seconds' => $this->decaySeconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $failures = self::extractRequiredIntegerValue($data, 'failures', 0);
        $lastFailureAt = self::extractRequiredIntegerValue($data, 'last_failure_at', 0);
        $decaySeconds = self::extractOptionalIntegerValue($data, 'decay_seconds');

        return new self(
            failures: $failures,
            lastFailureAt: $lastFailureAt,
            decaySeconds: $decaySeconds,
        );
    }

    public static function healthy(?int $decaySeconds = null): self
    {
        return new self(decaySeconds: $decaySeconds);
    }

    public static function failed(int $threshold = 999, ?int $decaySeconds = null): self
    {
        return new self(
            failures: max(1, $threshold),
            lastFailureAt: time(),
            decaySeconds: $decaySeconds,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function extractRequiredIntegerValue(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function extractOptionalIntegerValue(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
