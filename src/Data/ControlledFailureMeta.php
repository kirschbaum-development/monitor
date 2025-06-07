<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Data;

use Throwable;

final readonly class ControlledFailureMeta
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $name,
        public string $id,
        public string $traceId,
        public int $attempt,
        public float $durationMs,
        public ?Throwable $exception,
        public array $context = [],
        public bool $breakerTripped = false,
        public bool $escalated = false,
    ) {}

    public function failed(): bool
    {
        return $this->exception !== null;
    }

    public function succeeded(): bool
    {
        return $this->exception === null;
    }

    public function wasRetried(): bool
    {
        return $this->attempt > 1;
    }



    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'controlled_block' => $this->name,
            'controlled_block_id' => $this->id,
            'trace_id' => $this->traceId,
            'attempt' => $this->attempt,
            'duration_ms' => $this->durationMs,
            'status' => $this->failed() ? 'failed' : 'succeeded',
            'breaker_tripped' => $this->breakerTripped,
            'escalated' => $this->escalated,
            'exception' => $this->buildExceptionData(),
            'block_context' => $this->context,
        ];
    }

    /**
     * @return array{class: string, message: string, file: string, line: int}|null
     */
    private function buildExceptionData(): ?array
    {
        if ($this->exception === null) {
            return null;
        }

        return [
            'class' => get_class($this->exception),
            'message' => $this->exception->getMessage(),
            'file' => $this->exception->getFile(),
            'line' => $this->exception->getLine(),
        ];
    }
}
