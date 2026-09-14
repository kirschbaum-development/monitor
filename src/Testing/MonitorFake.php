<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\Monitor;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Runner;
use Kirschbaum\Monitor\Status;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Records every outcome and lets a test cann what a point returns or throws.
 *
 * Points execute for real through the live runner, so policies, corrections
 * and escalations run and their outcomes are recorded. A point registered with
 * returning() skips its callback and succeeds with the value; one registered
 * with failing() skips its callback and fails with the exception, which then
 * goes through the point's own corrections exactly as a real failure would.
 */
class MonitorFake extends Monitor implements Runner
{
    /** @var list<Outcome> */
    private array $outcomes = [];

    /** @var array<string, mixed> */
    private array $returning = [];

    /** @var array<string, Throwable> */
    private array $failing = [];

    public function __construct(Container $container, private readonly Runner $live)
    {
        parent::__construct($container);
    }

    public function execute(Control $control, Closure $callback): Outcome
    {
        $point = $control->name();

        if (array_key_exists($point, $this->failing)) {
            $exception = $this->failing[$point];
            $callback = function () use ($exception): never {
                throw $exception;
            };
        } elseif (array_key_exists($point, $this->returning)) {
            $value = $this->returning[$point];
            $callback = fn (): mixed => $value;
        }

        $outcome = $this->live->execute($control, $callback);

        $this->outcomes[] = $outcome;

        return $outcome;
    }

    /**
     * Make the point succeed with this value without running its callback.
     */
    public function returning(string $point, mixed $value): static
    {
        $this->returning[$point] = $value;
        unset($this->failing[$point]);

        return $this;
    }

    /**
     * Make the point's callback throw this, exercising its own corrections.
     */
    public function failing(string $point, Throwable $exception): static
    {
        $this->failing[$point] = $exception;
        unset($this->returning[$point]);

        return $this;
    }

    /**
     * @return list<Outcome>
     */
    public function outcomes(?string $point = null): array
    {
        if ($point === null) {
            return $this->outcomes;
        }

        return array_values(array_filter($this->outcomes, fn (Outcome $o): bool => $o->point === $point));
    }

    public function forget(): void
    {
        $this->outcomes = [];
    }

    /**
     * @param  Closure(Outcome): bool|null  $callback
     */
    public function assertRan(string $point, ?Closure $callback = null): static
    {
        $matching = $this->matching($point, $callback);

        Assert::assertNotEmpty($matching, $callback instanceof Closure
            ? "Control point [{$point}] ran, but no outcome matched the callback."
            : "Control point [{$point}] did not run.");

        return $this;
    }

    public function assertRanTimes(string $point, int $times): static
    {
        $count = count($this->outcomes($point));

        Assert::assertSame($times, $count, "Control point [{$point}] ran {$count} times, expected {$times}.");

        return $this;
    }

    public function assertNeverRan(string $point): static
    {
        Assert::assertEmpty($this->outcomes($point), "Control point [{$point}] ran, but was expected not to.");

        return $this;
    }

    /**
     * @param  Closure(Outcome): bool|null  $callback
     */
    public function assertSucceeded(string $point, ?Closure $callback = null): static
    {
        $this->assertStatus($point, Status::Succeeded, $callback);

        return $this;
    }

    /**
     * @param  class-string<Throwable>|null  $from
     */
    public function assertRecovered(string $point, ?string $from = null): static
    {
        $this->assertStatus($point, Status::Recovered, $from === null ? null : fn (Outcome $o): bool => $o->recoveredFrom === $from);

        return $this;
    }

    /**
     * @param  class-string<Throwable>|null  $with
     */
    public function assertEscalated(string $point, ?string $with = null): static
    {
        $this->assertStatus($point, Status::Escalated, $with === null ? null : fn (Outcome $o): bool => $o->exception instanceof $with);

        return $this;
    }

    public function assertRefused(string $point): static
    {
        $this->assertStatus($point, Status::Refused, null);

        return $this;
    }

    public function assertRetried(string $point, ?int $times = null): static
    {
        $this->assertRan($point);

        $matching = $this->matching($point, $times === null
            ? fn (Outcome $o): bool => $o->attempts > 1
            : fn (Outcome $o): bool => $o->attempts === $times + 1);

        Assert::assertNotEmpty($matching, $times === null
            ? "Control point [{$point}] was not retried."
            : "Control point [{$point}] was not retried exactly {$times} times.");

        return $this;
    }

    public function assertLimitBreached(string $point, string $limit): static
    {
        $this->assertRan($point);

        Assert::assertNotEmpty(
            $this->matching($point, fn (Outcome $o): bool => $o->breachedLimit($limit)),
            "Control point [{$point}] did not breach the [{$limit}] limit.",
        );

        return $this;
    }

    public function assertNothingEscalated(): static
    {
        $escalated = array_map(fn (Outcome $o): string => $o->point, array_filter($this->outcomes, fn (Outcome $o): bool => $o->escalated()));

        Assert::assertEmpty($escalated, 'Control points escalated: '.implode(', ', $escalated).'.');

        return $this;
    }

    public function assertNothingRan(): static
    {
        Assert::assertEmpty($this->outcomes, 'Control points ran: '.implode(', ', array_map(fn (Outcome $o): string => $o->point, $this->outcomes)).'.');

        return $this;
    }

    /**
     * @param  Closure(Outcome): bool|null  $callback
     */
    private function assertStatus(string $point, Status $status, ?Closure $callback): void
    {
        $this->assertRan($point);

        $matching = $this->matching($point, fn (Outcome $o): bool => $o->status === $status && (! $callback instanceof Closure || $callback($o)));

        $seen = implode(', ', array_map(fn (Outcome $o): string => $o->status->value, $this->outcomes($point)));

        Assert::assertNotEmpty($matching, "Control point [{$point}] did not end {$status->value}; saw: {$seen}.");
    }

    /**
     * @param  Closure(Outcome): bool|null  $callback
     * @return list<Outcome>
     */
    private function matching(string $point, ?Closure $callback): array
    {
        return array_values(array_filter(
            $this->outcomes($point),
            fn (Outcome $o): bool => ! $callback instanceof Closure || $callback($o),
        ));
    }
}
