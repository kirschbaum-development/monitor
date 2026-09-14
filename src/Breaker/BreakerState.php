<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Breaker;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * What the store knows about one circuit. Immutable; every change is a new state.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BreakerState implements Arrayable, JsonSerializable
{
    /**
     * @param  list<int>  $failures  unix timestamps of recent failures, oldest first
     */
    public function __construct(
        public State $state = State::Closed,
        public array $failures = [],
        public ?int $openedAt = null,
        public ?int $openFor = null,
    ) {}

    public static function closed(): self
    {
        return new self;
    }

    public function isClosed(): bool
    {
        return $this->state === State::Closed;
    }

    public function isOpen(): bool
    {
        return $this->state === State::Open;
    }

    public function isHalfOpen(): bool
    {
        return $this->state === State::HalfOpen;
    }

    public function failureCount(): int
    {
        return count($this->failures);
    }

    /**
     * Seconds until the open period ends, or zero when not open.
     */
    public function retryAfter(int $now): int
    {
        if ($this->openedAt === null || $this->openFor === null) {
            return 0;
        }

        return max(0, $this->openedAt + $this->openFor - $now);
    }

    public function probeDue(int $now): bool
    {
        return $this->isOpen() && $this->retryAfter($now) === 0;
    }

    public function withFailure(int $now, BreakerConfig $config): self
    {
        $failures = array_values(array_filter(
            $this->failures,
            fn (int $at): bool => $at > $now - $config->within,
        ));
        $failures[] = $now;

        if (count($failures) > $config->after) {
            $failures = array_slice($failures, -$config->after);
        }

        return new self(state: $this->state, failures: $failures, openedAt: $this->openedAt, openFor: $this->openFor);
    }

    public function opened(int $now, int $for): self
    {
        return new self(state: State::Open, failures: $this->failures, openedAt: $now, openFor: $for);
    }

    public function halfOpened(): self
    {
        return new self(state: State::HalfOpen, failures: $this->failures, openedAt: $this->openedAt, openFor: $this->openFor);
    }

    /**
     * @return array{state: string, failures: list<int>, opened_at: int|null, open_for: int|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'failures' => $this->failures,
            'opened_at' => $this->openedAt,
            'open_for' => $this->openFor,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $state = is_string($data['state'] ?? null) ? State::tryFrom($data['state']) : null;
        $failures = is_array($data['failures'] ?? null) ? array_values(array_filter($data['failures'], is_int(...))) : [];
        $openedAt = is_int($data['opened_at'] ?? null) ? $data['opened_at'] : null;
        $openFor = is_int($data['open_for'] ?? null) ? $data['open_for'] : null;

        return new self($state ?? State::Closed, $failures, $openedAt, $openFor);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
