<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Breaker;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Kirschbaum\Monitor\Events\BreakerClosed;
use Kirschbaum\Monitor\Events\BreakerHalfOpen;
use Kirschbaum\Monitor\Events\BreakerOpened;

/**
 * The circuit state machine, closed / open / half-open, kept in the cache so
 * every process sees the same circuit.
 *
 * Closed: attempts are allowed; failures inside the window are counted and
 * the "after"-th one opens the circuit. Open: attempts are refused until the
 * open period ends. Half-open: exactly one probe is allowed; its success closes
 * the circuit, its failure opens it again for a full period.
 */
class CircuitBreaker
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function state(string $name): BreakerState
    {
        $data = $this->cache()->get($this->key($name));

        return is_array($data) ? BreakerState::fromArray($data) : BreakerState::closed();
    }

    public function isOpen(string $name): bool
    {
        $state = $this->state($name);

        return $state->isOpen() && ! $state->probeDue($this->now());
    }

    public function isClosed(string $name): bool
    {
        return ! $this->isOpen($name);
    }

    /**
     * Decide whether an attempt may proceed, moving an expired open circuit to
     * half-open and handing out its single probe.
     */
    public function attempt(string $name, ?BreakerConfig $config = null): Decision
    {
        $config ??= BreakerConfig::defaults();
        $state = $this->state($name);
        $now = $this->now();

        if ($state->isClosed()) {
            return Decision::allow();
        }

        if ($state->isOpen() && ! $state->probeDue($now)) {
            return Decision::refuse($state->retryAfter($now));
        }

        if ($state->isOpen()) {
            $state = $state->halfOpened();
            $this->put($name, $state, $config);
            $this->events->dispatch(new BreakerHalfOpen($name, $state));
        }

        // Half-open: one probe at a time. The lock outlives the open period so
        // a probe that never reports back cannot hold the circuit half-open forever.
        if ($this->cache()->add($this->key($name).':probe', 1, $config->for)) {
            return Decision::probe();
        }

        return Decision::refuse(max(1, $state->retryAfter($now)));
    }

    public function recordFailure(string $name, ?BreakerConfig $config = null): BreakerState
    {
        $config ??= BreakerConfig::defaults();
        $now = $this->now();
        $state = $this->state($name)->withFailure($now, $config);

        if ($state->isHalfOpen() || $state->failureCount() >= $config->after) {
            $state = $state->opened($now, $config->for);
            $this->put($name, $state, $config);
            $this->cache()->forget($this->key($name).':probe');
            $this->events->dispatch(new BreakerOpened($name, $state));

            return $state;
        }

        $this->put($name, $state, $config);

        return $state;
    }

    public function recordSuccess(string $name): BreakerState
    {
        $previous = $this->state($name);

        $this->cache()->forget($this->key($name));
        $this->cache()->forget($this->key($name).':probe');

        $state = BreakerState::closed();

        if (! $previous->isClosed()) {
            $this->events->dispatch(new BreakerClosed($name, $state));
        }

        return $state;
    }

    /**
     * Open the circuit by hand, for instance from a health check.
     */
    public function open(string $name, ?int $forSeconds = null): BreakerState
    {
        $config = BreakerConfig::defaults();
        $state = $this->state($name)->opened($this->now(), $forSeconds ?? $config->for);

        $this->put($name, $state, $config->with(for: $state->openFor));
        $this->events->dispatch(new BreakerOpened($name, $state));

        return $state;
    }

    public function close(string $name): BreakerState
    {
        return $this->recordSuccess($name);
    }

    public function retryAfter(string $name): int
    {
        return $this->state($name)->retryAfter($this->now());
    }

    protected function now(): int
    {
        return Date::now()->getTimestamp();
    }

    private function put(string $name, BreakerState $state, BreakerConfig $config): void
    {
        // Keep the entry a little past the longest thing it describes.
        $ttl = max($config->within, $config->for) + 60;

        $this->cache()->put($this->key($name), $state->toArray(), $ttl);
    }

    private function key(string $name): string
    {
        return Config::string('monitor.breakers.prefix', 'monitor:breaker:').$name;
    }

    private function cache(): Repository
    {
        $store = Config::get('monitor.breakers.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
