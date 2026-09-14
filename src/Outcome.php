<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Throwable;

/**
 * The complete record of one run of a control point.
 *
 * Whether a caller sees it through attempt(), an event, the fake or the store,
 * it is the same object with the same fields.
 */
final readonly class Outcome
{
    /**
     * @param  array<string, array{threshold: int|float, actual: int|float}>  $limitsBreached
     * @param  list<array<string, mixed>>  $policies
     * @param  array<string, mixed>  $context
     * @param  list<array{at_ms: float, event: string, detail: array<string, mixed>}>  $timeline
     * @param  list<string>  $stack
     */
    public function __construct(
        public string $point,
        public string $id,
        public ?string $parentId,
        public string $traceId,
        public string $domain,
        public string $origin,
        public ?string $profile,
        public Status $status,
        public mixed $value,
        public ?Throwable $exception,
        public ?string $recoveredFrom,
        public int $attempts,
        public float $durationMs,
        public array $limitsBreached,
        public array $policies,
        public array $context,
        public array $timeline,
        public array $stack,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === Status::Succeeded;
    }

    public function recovered(): bool
    {
        return $this->status === Status::Recovered;
    }

    public function escalated(): bool
    {
        return $this->status === Status::Escalated;
    }

    public function refused(): bool
    {
        return $this->status === Status::Refused;
    }

    /**
     * Whether the run produced a usable value: it succeeded or was recovered.
     */
    public function hasValue(): bool
    {
        return $this->succeeded() || $this->recovered();
    }

    public function breachedLimit(string $name): bool
    {
        return array_key_exists($name, $this->limitsBreached);
    }

    public function info(): RunInfo
    {
        return new RunInfo(
            point: $this->point,
            id: $this->id,
            parentId: $this->parentId,
            traceId: $this->traceId,
            domain: $this->domain,
            origin: $this->origin,
            profile: $this->profile,
            stack: $this->stack,
            context: $this->context,
        );
    }

    /**
     * The scalar view of the outcome, without the value and with the exception
     * summarised. This is what records, the store and the fake expose.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'point' => $this->point,
            'run_id' => $this->id,
            'parent_run_id' => $this->parentId,
            'trace_id' => $this->traceId,
            'domain' => $this->domain,
            'origin' => $this->origin,
            'profile' => $this->profile,
            'status' => $this->status->value,
            'recovered_from' => $this->recoveredFrom,
            'exception' => $this->exception instanceof Throwable ? [
                'class' => $this->exception::class,
                'message' => $this->exception->getMessage(),
            ] : null,
            'attempts' => $this->attempts,
            'duration_ms' => $this->durationMs,
            'limits_breached' => $this->limitsBreached,
            'policies' => $this->policies,
            'context' => $this->context,
            'stack' => $this->stack,
        ];
    }
}
