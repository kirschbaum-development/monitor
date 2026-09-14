<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Kirschbaum\Monitor\Escalations\Escalation;
use Kirschbaum\Monitor\Events\EscalationFailed;
use Kirschbaum\Monitor\Events\PointEnded;
use Kirschbaum\Monitor\Events\PointEscalated;
use Kirschbaum\Monitor\Events\PointLimitBreached;
use Kirschbaum\Monitor\Events\PointRecovered;
use Kirschbaum\Monitor\Events\PointRefused;
use Kirschbaum\Monitor\Events\PointRetried;
use Kirschbaum\Monitor\Events\PointStarted;
use Kirschbaum\Monitor\Limits\Attempts;
use Kirschbaum\Monitor\Limits\Within;
use Kirschbaum\Monitor\Policies\Policy;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Kirschbaum\Monitor\Risks\EnsureFailed;
use Kirschbaum\Monitor\Support\ChildEscalations;
use Kirschbaum\Monitor\Trace\Trace;
use Throwable;

/**
 * One execution of a control point, from the first event to the outcome.
 */
final class Run
{
    private RunInfo $info;

    private float $startedAt;

    private int $attempt = 1;

    private ?BreakerOpen $refusal = null;

    /** @var list<array{at_ms: float, event: string, detail: array<string, mixed>}> */
    private array $timeline = [];

    /**
     * @param  Closure(): mixed  $callback
     */
    public function __construct(
        private readonly Control $control,
        private readonly Closure $callback,
        private readonly Container $container,
        private readonly Dispatcher $events,
        private readonly Trace $trace,
        private readonly ControlStack $stack,
    ) {}

    public function execute(): Outcome
    {
        $this->begin();

        try {
            $value = $this->pipeline()();
            $this->assertEnsures($value);

            $outcome = $this->outcome(Status::Succeeded, $value, null, null);
        } catch (Throwable $e) {
            $outcome = $this->handle($e);
        } finally {
            $this->stack->pop();
        }

        $this->dispatchEnd($outcome);

        return $outcome;
    }

    public function info(): RunInfo
    {
        return $this->info;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    /**
     * The hard cap on attempts for this run.
     */
    public function maxAttempts(): int
    {
        return $this->control->resolvedAttempts()->max ?? PHP_INT_MAX;
    }

    /**
     * Called by a retry policy after a failed attempt and its backoff.
     */
    public function retried(Throwable $exception, int $backoffMs): void
    {
        $failed = $this->attempt;
        $this->attempt++;

        $this->note('retried', ['attempt' => $failed, 'exception' => $exception::class, 'backoff_ms' => $backoffMs]);
        $this->events->dispatch(new PointRetried($this->info, $exception, $failed, $backoffMs));
    }

    /**
     * Called by the breaker policy when it refuses the attempt.
     */
    public function refused(BreakerOpen $refusal): void
    {
        $this->refusal = $refusal;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function note(string $event, array $detail = []): void
    {
        $this->timeline[] = ['at_ms' => $this->elapsedMs(), 'event' => $event, 'detail' => $detail];
    }

    private function begin(): void
    {
        $this->startedAt = hrtime(true) / 1e6;
        $parentId = $this->stack->currentRunId();
        $id = (string) Str::ulid();

        $this->stack->push($this->control->name(), $id);

        $this->info = new RunInfo(
            point: $this->control->name(),
            id: $id,
            parentId: $parentId,
            traceId: $this->trace->id(),
            domain: $this->control->resolvedDomain(),
            origin: $this->control->origin(),
            profile: $this->control->profileName(),
            stack: $this->stack->names(),
            context: $this->control->context(),
        );

        $this->note('started');
        $this->events->dispatch(new PointStarted($this->info));
    }

    /**
     * Policies wrap the callback outermost first.
     *
     * @return Closure(): mixed
     */
    private function pipeline(): Closure
    {
        $next = $this->callback;

        foreach (array_reverse($this->control->resolvedPolicies()) as $policy) {
            $next = fn (): mixed => $policy->around($this, $next);
        }

        return $next;
    }

    private function assertEnsures(mixed $value): void
    {
        foreach ($this->control->ensures() as $ensure) {
            if (! $ensure->holdsFor($value)) {
                $this->note('ensure.failed', ['reason' => $ensure->reason]);

                throw new EnsureFailed($ensure->reason, $value);
            }
        }
    }

    private function handle(Throwable $e): Outcome
    {
        $refused = $this->refusal instanceof BreakerOpen && $e === $this->refusal;

        foreach ($this->control->risks() as $risk) {
            if (! $e instanceof $risk['class']) {
                continue;
            }

            $this->note('recovering', ['risk' => $risk['class']]);

            try {
                $value = $risk['handler']($e, $this->outcome(Status::Escalated, null, $e, null));
            } catch (Throwable $fromHandler) {
                $this->note('correction.threw', ['risk' => $risk['class'], 'exception' => $fromHandler::class]);

                return $this->escalate($fromHandler);
            }

            return $this->outcome(Status::Recovered, $value, $e, $risk['class']);
        }

        if ($refused) {
            $this->note('refused');

            return $this->outcome(Status::Refused, null, $e, null);
        }

        return $this->escalate($e);
    }

    private function escalate(Throwable $e): Outcome
    {
        $this->note('escalated', ['exception' => $e::class]);

        if ($this->info->parentId !== null) {
            ChildEscalations::record($e, $this->info->point);
        }

        return $this->outcome(Status::Escalated, null, $e, null);
    }

    private function dispatchEnd(Outcome $outcome): void
    {
        foreach ($outcome->limitsBreached as $name => $breach) {
            $this->events->dispatch(new PointLimitBreached($this->info, $name, $breach['threshold'], $breach['actual']));
        }

        match ($outcome->status) {
            Status::Recovered => $this->events->dispatch(new PointRecovered($outcome)),
            Status::Escalated => $this->events->dispatch(new PointEscalated($outcome)),
            Status::Refused => $this->events->dispatch(new PointRefused($outcome)),
            Status::Succeeded => null,
        };

        if ($outcome->escalated()) {
            $this->notifyEscalation($outcome);
        }

        $this->events->dispatch(new PointEnded($outcome));
    }

    private function notifyEscalation(Outcome $outcome): void
    {
        $escalation = $this->control->escalation();

        if ($escalation === null) {
            return;
        }

        try {
            if ($escalation instanceof Closure) {
                $escalation($outcome);
            } else {
                $handler = $this->container->make($escalation);

                if ($handler instanceof Escalation) {
                    $handler->handle($outcome);
                }
            }
        } catch (Throwable $fromEscalation) {
            // Recorded on the outcome's timeline through the ended event's
            // listeners is too late; note it where the recorder can see it.
            $this->events->dispatch(new EscalationFailed($outcome, $fromEscalation));
        }
    }

    private function outcome(Status $status, mixed $value, ?Throwable $exception, ?string $recoveredFrom): Outcome
    {
        $durationMs = $this->elapsedMs();
        $limits = [];

        $within = $this->control->resolvedWithin();

        if ($within instanceof Within && $within->breachedBy($durationMs)) {
            $limits['duration'] = ['threshold' => $within->thresholdMs(), 'actual' => round($durationMs, 3)];
        }

        $attemptsLimit = $this->control->resolvedAttempts();

        if ($attemptsLimit instanceof Attempts && $this->attempt >= $attemptsLimit->max && $status->isFailure()) {
            $limits['attempts'] = ['threshold' => $attemptsLimit->max, 'actual' => $this->attempt];
        }

        return new Outcome(
            point: $this->info->point,
            id: $this->info->id,
            parentId: $this->info->parentId,
            traceId: $this->info->traceId,
            domain: $this->info->domain,
            origin: $this->info->origin,
            profile: $this->info->profile,
            status: $status,
            value: $value,
            exception: $exception,
            recoveredFrom: $recoveredFrom,
            attempts: $this->attempt,
            durationMs: round($durationMs, 3),
            limitsBreached: $limits,
            policies: array_map(fn (Policy $policy): array => $policy->describe(), $this->control->resolvedPolicies()),
            context: $this->info->context,
            timeline: $this->timeline,
            stack: $this->info->stack,
        );
    }

    private function elapsedMs(): float
    {
        return hrtime(true) / 1e6 - $this->startedAt;
    }
}
