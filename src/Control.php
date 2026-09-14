<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\DeadlockException;
use Kirschbaum\Monitor\Escalations\Escalation;
use Kirschbaum\Monitor\Exceptions\InvalidControlPoint;
use Kirschbaum\Monitor\Limits\Attempts;
use Kirschbaum\Monitor\Limits\Ensure;
use Kirschbaum\Monitor\Limits\Within;
use Kirschbaum\Monitor\Policies\Breaker;
use Kirschbaum\Monitor\Policies\Policy;
use Kirschbaum\Monitor\Policies\Retry;
use Kirschbaum\Monitor\Policies\Transaction;
use Kirschbaum\Monitor\Support\Domain;
use Kirschbaum\Monitor\Support\PointName;
use Kirschbaum\Monitor\Support\Profiles;
use Throwable;

/**
 * The declaration of a control point: what it is called, where it lives, what
 * it tolerates, what bounds it, and who is told when it fails.
 *
 * Reads top to bottom as a contract. Two terminals: run() returns the value or
 * throws; attempt() returns the Outcome and never throws.
 */
final class Control
{
    private string $origin;

    private ?string $domain = null;

    private ?string $profile = null;

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, Policy> keyed by type for the shipped policies, by class for others */
    private array $policies = [];

    private ?Within $within = null;

    private ?Attempts $attempts = null;

    /** @var list<Ensure> */
    private array $ensures = [];

    /** @var list<array{class: class-string<Throwable>, handler: Closure}> */
    private array $risks = [];

    /** @var Closure|class-string<Escalation>|null */
    private Closure|string|null $escalation = null;

    public function __construct(private readonly string $name, string|object|null $origin = null)
    {
        PointName::validate($name);

        $this->origin = $this->originOf($origin);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The class this point belongs to. Usually $this at the call site.
     */
    public function from(string|object $origin): self
    {
        $this->origin = $this->originOf($origin);

        return $this;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * Override the domain derived from the origin's namespace.
     */
    public function domain(string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function resolvedDomain(): string
    {
        return $this->domain ?? Domain::resolve($this->origin);
    }

    /**
     * Context recorded with every transition. Merged with what is already there.
     *
     * @param  array<string, mixed>  $context
     */
    public function with(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Start from a configured bundle of policies and limits. Anything declared
     * on the point afterwards overrides the profile's version of it.
     */
    public function profile(string $name): self
    {
        Profiles::definition($name);

        $this->profile = $name;

        return $this;
    }

    public function profileName(): ?string
    {
        return $this->profile;
    }

    /**
     * @param  list<class-string<Throwable>>  $on
     * @param  list<class-string<Throwable>>  $except
     */
    public function retry(int $times = 2, int $backoffMs = 200, float $multiplier = 2.0, bool $jitter = true, array $on = [], array $except = []): self
    {
        $this->policies['retry'] = Retry::times($times)->backoff($backoffMs, $multiplier, $jitter)->on($on)->except($except);

        return $this;
    }

    /**
     * @param  list<class-string<Throwable>>  $on
     * @param  list<class-string<Throwable>>  $except
     */
    public function transaction(int $retries = 0, array $on = [DeadlockException::class], array $except = [], ?string $connection = null): self
    {
        $this->policies['transaction'] = Transaction::retries($retries)->on($on)->except($except)->connection($connection);

        return $this;
    }

    public function breaker(string $name, ?int $after = null, ?int $within = null, ?int $for = null): self
    {
        $breaker = Breaker::named($name);

        if ($after !== null) {
            $breaker->after($after, $within);
        }

        if ($for !== null) {
            $breaker->for($for);
        }

        $this->policies['breaker'] = $breaker;

        return $this;
    }

    /**
     * Any policy, including your own. A shipped policy passed here replaces the
     * one of the same type.
     */
    public function policy(Policy $policy): self
    {
        $this->policies[$this->policyKey($policy)] = $policy;

        return $this;
    }

    /**
     * The whole run should finish inside this many seconds. Recorded, never fatal.
     */
    public function within(float|int $seconds): self
    {
        $this->within = new Within((float) $seconds);

        return $this;
    }

    /**
     * A hard cap on attempts, whatever the retry policies ask for.
     */
    public function attempts(int $max): self
    {
        $this->attempts = new Attempts(max(1, $max));

        return $this;
    }

    /**
     * A post-condition on the value. Fails the run with EnsureFailed when false.
     *
     * @param  Closure(mixed): bool  $check
     */
    public function ensure(Closure $check, string $reason = 'result did not satisfy ensure()'): self
    {
        $this->ensures[] = new Ensure($check, $reason);

        return $this;
    }

    /**
     * Declare a risk and its correction. The handler's return value becomes the
     * result of the point. First matching class wins, in declaration order.
     *
     * @param  Closure(Throwable, Outcome): mixed  $handler
     */
    public function recover(string $class, Closure $handler): self
    {
        if (! is_a($class, Throwable::class, true)) {
            throw new InvalidControlPoint(sprintf('recover() expects a Throwable class, got "%s".', $class));
        }

        $this->risks[] = ['class' => $class, 'handler' => $handler];

        return $this;
    }

    /**
     * Who is told when the point fails with something no correction covers.
     *
     * @param  Closure(Outcome): void|string  $escalation  a closure or an Escalation class name
     */
    public function escalate(Closure|string $escalation): self
    {
        if (is_string($escalation) && ! is_a($escalation, Escalation::class, true)) {
            throw new InvalidControlPoint(sprintf('escalate() expects a Closure or an Escalation class, got "%s".', $escalation));
        }

        $this->escalation = $escalation;

        return $this;
    }

    /**
     * Execute and return the value, or throw what escaped.
     *
     * @param  Closure(): mixed  $callback
     */
    public function run(Closure $callback): mixed
    {
        $outcome = $this->attempt($callback);

        if ($outcome->status->isFailure() && $outcome->exception instanceof Throwable) {
            throw $outcome->exception;
        }

        return $outcome->value;
    }

    /**
     * Execute and return the Outcome. Never throws for what the operation did.
     *
     * @param  Closure(): mixed  $callback
     */
    public function attempt(Closure $callback): Outcome
    {
        return Container::getInstance()->make(Runner::class)->execute($this, $callback);
    }

    /**
     * The policies that will run, profile first, ordered outermost first.
     *
     * @return list<Policy>
     */
    public function resolvedPolicies(): array
    {
        $policies = $this->profile !== null
            ? array_merge(Profiles::policies($this->profile, $this->name), $this->policies)
            : $this->policies;

        $list = array_values($policies);

        usort($list, fn (Policy $a, Policy $b): int => $a->order() <=> $b->order());

        return $list;
    }

    public function resolvedWithin(): ?Within
    {
        return $this->within ?? ($this->profile !== null ? Profiles::within($this->profile) : null);
    }

    public function resolvedAttempts(): ?Attempts
    {
        return $this->attempts ?? ($this->profile !== null ? Profiles::attempts($this->profile) : null);
    }

    /**
     * @return list<Ensure>
     */
    public function ensures(): array
    {
        return $this->ensures;
    }

    /**
     * @return list<array{class: class-string<Throwable>, handler: Closure}>
     */
    public function risks(): array
    {
        return $this->risks;
    }

    /**
     * @return list<class-string<Throwable>>
     */
    public function riskClasses(): array
    {
        return array_map(fn (array $risk): string => $risk['class'], $this->risks);
    }

    public function hasCatchAll(): bool
    {
        return in_array(Throwable::class, $this->riskClasses(), true);
    }

    /**
     * @return Closure|class-string<Escalation>|null
     */
    public function escalation(): Closure|string|null
    {
        return $this->escalation;
    }

    public function hasEscalation(): bool
    {
        return $this->escalation !== null;
    }

    /**
     * The static description of this point: everything the inventory can know
     * without running it.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $limits = [];

        if (($within = $this->resolvedWithin()) instanceof Within) {
            $limits[] = $within->describe();
        }

        if (($attempts = $this->resolvedAttempts()) instanceof Attempts) {
            $limits[] = $attempts->describe();
        }

        foreach ($this->ensures as $ensure) {
            $limits[] = $ensure->describe();
        }

        $escalation = $this->escalation;

        return [
            'point' => $this->name,
            'origin' => $this->origin,
            'domain' => $this->resolvedDomain(),
            'profile' => $this->profile,
            'policies' => array_map(fn (Policy $policy): array => $policy->describe(), $this->resolvedPolicies()),
            'limits' => $limits,
            'risks' => $this->riskClasses(),
            'catch_all' => $this->hasCatchAll(),
            'escalation' => is_string($escalation) ? $escalation : ($escalation instanceof Closure ? 'closure' : null),
        ];
    }

    private function originOf(string|object|null $origin): string
    {
        if ($origin === null) {
            return Control::class;
        }

        return is_object($origin) ? $origin::class : $origin;
    }

    private function policyKey(Policy $policy): string
    {
        return match (true) {
            $policy instanceof Retry => 'retry',
            $policy instanceof Transaction => 'transaction',
            $policy instanceof Breaker => 'breaker',
            default => $policy::class,
        };
    }
}
