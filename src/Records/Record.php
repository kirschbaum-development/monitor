<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Records;

use Kirschbaum\Monitor\Breaker\BreakerState;
use Kirschbaum\Monitor\Events\EscalationFailed;
use Kirschbaum\Monitor\Events\PointLimitBreached;
use Kirschbaum\Monitor\Events\PointRetried;
use Kirschbaum\Monitor\Outcome;
use Kirschbaum\Monitor\Risks\BreakerOpen;
use Kirschbaum\Monitor\RunInfo;
use Throwable;

/**
 * The one shape every record has. Field names here are the schema; see
 * resources/schema/record-1.json.
 */
final class Record
{
    public const string SCHEMA = 'monitor/1';

    /**
     * @return array<string, mixed>
     */
    public static function started(RunInfo $run): array
    {
        return self::base('point.started', $run) + [
            'attempt' => 1,
            'message' => sprintf('%s %s started', self::prefix($run), $run->point),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function retried(PointRetried $event): array
    {
        return self::base('point.retried', $event->run) + [
            'attempt' => $event->attempt,
            'backoff_ms' => $event->backoffMs,
            'exception' => ExceptionSummary::from($event->exception),
            'message' => sprintf('%s %s attempt %d failed with %s, retrying after %dms', self::prefix($event->run), $event->run->point, $event->attempt, $event->exception::class, $event->backoffMs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function limit(PointLimitBreached $event): array
    {
        return self::base('point.limit', $event->run) + [
            'limit' => ['name' => $event->limit, 'threshold' => $event->threshold, 'actual' => $event->actual],
            'message' => sprintf('%s %s breached the %s limit: %s against %s', self::prefix($event->run), $event->run->point, $event->limit, self::number($event->actual), self::number($event->threshold)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function recovered(Outcome $outcome): array
    {
        return self::fromOutcome('point.recovered', $outcome) + [
            'risk' => $outcome->recoveredFrom,
            'message' => sprintf('%s %s recovered from %s in %sms', self::prefix($outcome->info()), $outcome->point, $outcome->recoveredFrom, self::number($outcome->durationMs)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function escalated(Outcome $outcome): array
    {
        $class = $outcome->exception instanceof Throwable ? $outcome->exception::class : 'unknown';

        return self::fromOutcome('point.escalated', $outcome) + [
            'message' => sprintf('%s %s escalated %s after %d attempt(s) in %sms', self::prefix($outcome->info()), $outcome->point, $class, $outcome->attempts, self::number($outcome->durationMs)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function refused(Outcome $outcome): array
    {
        $breaker = $outcome->exception instanceof BreakerOpen ? $outcome->exception : null;

        return self::fromOutcome('point.refused', $outcome) + [
            'breaker' => $breaker instanceof BreakerOpen ? ['name' => $breaker->breaker, 'state' => 'open', 'retry_after_s' => $breaker->retryAfterSeconds] : null,
            'message' => sprintf('%s %s refused: breaker %s is open', self::prefix($outcome->info()), $outcome->point, $breaker instanceof BreakerOpen ? $breaker->breaker : 'unknown'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ended(Outcome $outcome): array
    {
        return self::fromOutcome('point.ended', $outcome) + [
            'message' => sprintf('%s %s %s in %sms', self::prefix($outcome->info()), $outcome->point, $outcome->status->value, self::number($outcome->durationMs)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function escalationFailed(EscalationFailed $event): array
    {
        return self::fromOutcome('escalation.failed', $event->outcome) + [
            'escalation_exception' => ExceptionSummary::from($event->exception),
            'message' => sprintf('%s %s escalation handler threw %s; the original failure still propagates', self::prefix($event->outcome->info()), $event->outcome->point, $event->exception::class),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function breaker(string $event, string $name, BreakerState $state): array
    {
        return [
            'schema' => self::SCHEMA,
            'event' => $event,
            'breaker' => ['name' => $name, 'state' => $state->state->value, 'failures' => $state->failureCount(), 'open_for' => $state->openFor],
            'message' => sprintf('[Monitor] breaker %s is now %s', $name, $state->state->value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromOutcome(string $event, Outcome $outcome): array
    {
        $record = self::base($event, $outcome->info()) + [
            'status' => $outcome->status->value,
            'attempts' => $outcome->attempts,
            'duration_ms' => $outcome->durationMs,
            'limits_breached' => $outcome->limitsBreached,
        ];

        if ($outcome->exception instanceof Throwable) {
            $record['exception'] = ExceptionSummary::from($outcome->exception);
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(string $event, RunInfo $run): array
    {
        return [
            'schema' => self::SCHEMA,
            'event' => $event,
            'point' => $run->point,
            'run_id' => $run->id,
            'parent_run_id' => $run->parentId,
            'stack' => $run->stack,
            'trace_id' => $run->traceId,
            'domain' => $run->domain,
            'origin' => $run->origin,
            'profile' => $run->profile,
            'context' => $run->context,
        ];
    }

    private static function prefix(RunInfo $run): string
    {
        $short = substr($run->origin, (int) strrpos('\\'.$run->origin, '\\'));

        return sprintf('[%s:%s]', $run->domain, $short);
    }

    private static function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
