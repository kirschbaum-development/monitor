<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Kirschbaum\Monitor\Data\CircuitBreakerState;

class CircuitBreaker
{
    private const CACHE_PREFIX = 'circuit_breaker:';

    private const CACHE_TTL_BUFFER_SECONDS = 60;

    public function __construct(
        private readonly int $defaultThreshold = 5,
        private readonly int $defaultDecaySeconds = 300,
    ) {
        if ($this->defaultThreshold < 1) {
            throw new InvalidArgumentException('Threshold must be at least 1.');
        }

        if ($this->defaultDecaySeconds < 1) {
            throw new InvalidArgumentException('Decay seconds must be at least 1.');
        }
    }

    public function isOpen(string $name, ?int $threshold = null, ?int $decaySeconds = null): bool
    {
        $state = $this->getState($name);

        if ($state === null || $state->isHealthy()) {
            return false;
        }

        $threshold ??= $this->defaultThreshold;
        $decaySeconds ??= $this->defaultDecaySeconds;

        if ($state->hasExpired($decaySeconds)) {
            $this->reset($name);

            return false;
        }

        return $state->exceedsThreshold($threshold);
    }

    public function isClosed(string $name, ?int $threshold = null, ?int $decaySeconds = null): bool
    {
        return ! $this->isOpen($name, $threshold, $decaySeconds);
    }

    public function recordFailure(string $name, ?int $decaySeconds = null): self
    {
        $decaySeconds ??= $this->defaultDecaySeconds;
        $currentState = $this->getState($name) ?? CircuitBreakerState::healthy($decaySeconds);
        $newState = $currentState->recordFailure($decaySeconds);

        $this->setState($name, $newState, $decaySeconds);

        return $this;
    }

    public function recordSuccess(string $name): self
    {
        $this->reset($name);

        return $this;
    }

    public function forceOpen(string $name, ?int $decaySeconds = null): self
    {
        $decaySeconds ??= $this->defaultDecaySeconds;
        $state = CircuitBreakerState::failed($this->defaultThreshold, $decaySeconds);

        $this->setState($name, $state, $decaySeconds);

        return $this;
    }

    public function reset(string $name): self
    {
        Cache::forget($this->cacheKey($name));

        return $this;
    }

    public function getState(string $name): ?CircuitBreakerState
    {
        $data = Cache::get($this->cacheKey($name));

        if ($data === null) {
            return null;
        }

        /** @var array<string, mixed> $arrayData */
        $arrayData = (array) $data;

        return CircuitBreakerState::fromArray($arrayData);
    }

    public function getFailureCount(string $name): int
    {
        return $this->getState($name)->failures ?? 0;
    }

    public function getLastFailureTime(string $name): ?int
    {
        $lastFailureAt = $this->getState($name)?->lastFailureAt;

        return $lastFailureAt > 0 ? $lastFailureAt : null;
    }

    public function isHealthy(string $name): bool
    {
        $state = $this->getState($name);

        return $state === null || $state->isHealthy();
    }

    private function setState(string $name, CircuitBreakerState $state, int $decaySeconds): void
    {
        $ttl = $decaySeconds + self::CACHE_TTL_BUFFER_SECONDS;

        Cache::put($this->cacheKey($name), $state->toArray(), $ttl);
    }

    private function cacheKey(string $name): string
    {
        return self::CACHE_PREFIX.$name;
    }
}
