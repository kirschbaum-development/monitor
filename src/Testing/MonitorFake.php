<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Testing;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\Fake;
use Kirschbaum\Monitor\Contracts\Runner;
use Kirschbaum\Monitor\Control;
use Kirschbaum\Monitor\Monitor;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Status;
use Kirschbaum\Monitor\Support\PointName;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Records every outcome and lets a test decide what a point returns or throws.
 *
 * Points execute for real through the live runner, so policies, corrections
 * and escalations run and their outcomes are recorded. A point registered with
 * returning() skips its callback and succeeds with the value; one registered
 * with failing() skips its callback and fails with the exception, which then
 * goes through the point's own corrections exactly as a real failure would.
 */
class MonitorFake extends Monitor implements Fake, Runner
{
    /** @var list<Outcome> */
    protected array $outcomes = [];

    /** @var array<string, mixed> */
    protected array $returning = [];

    /** @var array<string, Throwable> */
    protected array $failing = [];

    public function __construct(Container $container, protected readonly Runner $live)
    {
        parent::__construct($container);
    }

    /**
     * Execute the point, substituting a canned value or failure when one is registered.
     */
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
    public function returning(string|BackedEnum $point, mixed $value): static
    {
        $name = PointName::of($point);
        $this->returning[$name] = $value;
        unset($this->failing[$name]);

        return $this;
    }

    /**
     * Make the point's callback throw this, exercising its own corrections.
     */
    public function failing(string|BackedEnum $point, Throwable $exception): static
    {
        $name = PointName::of($point);
        $this->failing[$name] = $exception;
        unset($this->returning[$name]);

        return $this;
    }

    /**
     * Get the recorded outcomes, optionally for one point.
     *
     * @return Collection<int, Outcome>
     */
    public function outcomes(string|BackedEnum|null $point = null): Collection
    {
        $outcomes = new Collection($this->outcomes);

        if ($point === null) {
            return $outcomes;
        }

        $name = PointName::of($point);

        return $outcomes->filter(fn (Outcome $o): bool => $o->point === $name)->values();
    }

    /**
     * Discard every recorded outcome.
     */
    public function forget(): void
    {
        $this->outcomes = [];
    }

    /**
     * Assert the point ran at least once, optionally with an outcome matching the callback.
     *
     * @param  (Closure(Outcome): bool)|null  $callback
     */
    public function assertRan(string|BackedEnum $point, ?Closure $callback = null): static
    {
        $name = PointName::of($point);

        Assert::assertNotEmpty($this->matching($name, $callback), $callback instanceof Closure
            ? "The control point [{$name}] ran, but no outcome matched the callback."
            : "The control point [{$name}] did not run.");

        return $this;
    }

    /**
     * Assert the point did not run, or ran with no outcome matching the callback.
     *
     * @param  (Closure(Outcome): bool)|null  $callback
     */
    public function assertNotRan(string|BackedEnum $point, ?Closure $callback = null): static
    {
        $name = PointName::of($point);

        Assert::assertEmpty($this->matching($name, $callback), $callback instanceof Closure
            ? "The control point [{$name}] ran with an outcome matching the callback, but was expected not to."
            : "The control point [{$name}] ran, but was expected not to.");

        return $this;
    }

    /**
     * Assert the point ran exactly once.
     */
    public function assertRanOnce(string|BackedEnum $point): static
    {
        return $this->assertRanTimes($point, 1);
    }

    /**
     * Assert the point ran exactly this many times.
     */
    public function assertRanTimes(string|BackedEnum $point, int $times): static
    {
        $name = PointName::of($point);
        $count = $this->outcomes($name)->count();

        Assert::assertSame($times, $count, "The control point [{$name}] ran {$count} times instead of {$times} times.");

        return $this;
    }

    /**
     * Assert the point succeeded, optionally with an outcome matching the callback.
     *
     * @param  (Closure(Outcome): bool)|null  $callback
     */
    public function assertSucceeded(string|BackedEnum $point, ?Closure $callback = null): static
    {
        return $this->assertStatus($point, Status::Succeeded, $callback);
    }

    /**
     * Assert the point recovered, optionally from the given risk class or matching the callback.
     *
     * @param  (Closure(Outcome): bool)|class-string<Throwable>|null  $from
     */
    public function assertRecovered(string|BackedEnum $point, Closure|string|null $from = null): static
    {
        return $this->assertStatus($point, Status::Recovered, $this->risk($from));
    }

    /**
     * Assert the point did not recover, or not from the given risk class.
     *
     * @param  (Closure(Outcome): bool)|class-string<Throwable>|null  $from
     */
    public function assertNotRecovered(string|BackedEnum $point, Closure|string|null $from = null): static
    {
        return $this->assertNotStatus($point, Status::Recovered, $this->risk($from));
    }

    /**
     * Assert the point escalated, optionally with the given exception class or matching the callback.
     *
     * @param  (Closure(Outcome): bool)|class-string<Throwable>|null  $with
     */
    public function assertEscalated(string|BackedEnum $point, Closure|string|null $with = null): static
    {
        return $this->assertStatus($point, Status::Escalated, $this->exception($with));
    }

    /**
     * Assert the point did not escalate, or not with the given exception class.
     *
     * @param  (Closure(Outcome): bool)|class-string<Throwable>|null  $with
     */
    public function assertNotEscalated(string|BackedEnum $point, Closure|string|null $with = null): static
    {
        return $this->assertNotStatus($point, Status::Escalated, $this->exception($with));
    }

    /**
     * Assert the point was refused by an open breaker.
     */
    public function assertRefused(string|BackedEnum $point): static
    {
        return $this->assertStatus($point, Status::Refused, null);
    }

    /**
     * Assert the point was never refused.
     */
    public function assertNotRefused(string|BackedEnum $point): static
    {
        return $this->assertNotStatus($point, Status::Refused, null);
    }

    /**
     * Assert the point was retried, optionally exactly this many times.
     */
    public function assertRetried(string|BackedEnum $point, ?int $times = null): static
    {
        $name = PointName::of($point);
        $this->assertRan($name);

        Assert::assertNotEmpty($this->matching($name, $this->retried($times)), $times === null
            ? "The control point [{$name}] was not retried."
            : "The control point [{$name}] was not retried exactly {$times} times.");

        return $this;
    }

    /**
     * Assert the point was never retried, or never exactly this many times.
     */
    public function assertNotRetried(string|BackedEnum $point, ?int $times = null): static
    {
        $name = PointName::of($point);

        Assert::assertEmpty($this->matching($name, $this->retried($times)), $times === null
            ? "The control point [{$name}] was retried, but was expected not to be."
            : "The control point [{$name}] was retried exactly {$times} times, but was expected not to be.");

        return $this;
    }

    /**
     * Assert an outcome of the point breached the named limit.
     */
    public function assertLimitBreached(string|BackedEnum $point, string $limit): static
    {
        $name = PointName::of($point);
        $this->assertRan($name);

        Assert::assertNotEmpty(
            $this->matching($name, fn (Outcome $o): bool => $o->breachedLimit($limit)),
            "The control point [{$name}] did not breach the [{$limit}] limit.",
        );

        return $this;
    }

    /**
     * Assert no point escalated.
     */
    public function assertNothingEscalated(): static
    {
        $escalated = $this->outcomes()->filter(fn (Outcome $o): bool => $o->escalated())->map(fn (Outcome $o): string => $o->point);

        Assert::assertEmpty($escalated->all(), 'Control points escalated: '.$escalated->implode(', ').'.');

        return $this;
    }

    /**
     * Assert no point ran.
     */
    public function assertNothingRan(): static
    {
        $ran = $this->outcomes()->map(fn (Outcome $o): string => $o->point);

        Assert::assertEmpty($ran->all(), 'Control points ran: '.$ran->implode(', ').'.');

        return $this;
    }

    /**
     * @param  (Closure(Outcome): bool)|null  $callback
     */
    protected function assertStatus(string|BackedEnum $point, Status $status, ?Closure $callback): static
    {
        $name = PointName::of($point);
        $this->assertRan($name);

        $matching = $this->matching($name, fn (Outcome $o): bool => $o->status === $status && (! $callback instanceof Closure || $callback($o)));
        $seen = $this->outcomes($name)->map(fn (Outcome $o): string => $o->status->value)->implode(', ');

        Assert::assertNotEmpty($matching, "The control point [{$name}] did not end {$status->value}; saw: {$seen}.");

        return $this;
    }

    /**
     * @param  (Closure(Outcome): bool)|null  $callback
     */
    protected function assertNotStatus(string|BackedEnum $point, Status $status, ?Closure $callback): static
    {
        $name = PointName::of($point);

        $matching = $this->matching($name, fn (Outcome $o): bool => $o->status === $status && (! $callback instanceof Closure || $callback($o)));

        Assert::assertEmpty($matching, "The control point [{$name}] ended {$status->value}, but was expected not to.");

        return $this;
    }

    /**
     * @param  (Closure(Outcome): bool)|null  $callback
     * @return list<Outcome>
     */
    protected function matching(string $point, ?Closure $callback): array
    {
        return array_values($this->outcomes($point)->filter(fn (Outcome $o): bool => ! $callback instanceof Closure || $callback($o))->all());
    }

    /**
     * @param  (Closure(Outcome): bool)|class-string<Throwable>|null  $from
     * @return (Closure(Outcome): bool)|null
     */
    private function risk(Closure|string|null $from): ?Closure
    {
        return is_string($from) ? fn (Outcome $o): bool => $o->recoveredFrom === $from : $from;
    }

    /**
     * @param  (Closure(Outcome): bool)|class-string<Throwable>|null  $with
     * @return (Closure(Outcome): bool)|null
     */
    private function exception(Closure|string|null $with): ?Closure
    {
        return is_string($with) ? fn (Outcome $o): bool => $o->exception instanceof $with : $with;
    }

    /**
     * @return Closure(Outcome): bool
     */
    private function retried(?int $times): Closure
    {
        return $times === null
            ? fn (Outcome $o): bool => $o->attempts > 1
            : fn (Outcome $o): bool => $o->attempts === $times + 1;
    }
}
