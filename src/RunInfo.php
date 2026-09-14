<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

/**
 * The identity of one run of a control point, known before anything executes
 * and carried by the events that fire while it is still in progress.
 */
final readonly class RunInfo
{
    /**
     * @param  list<string>  $stack
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $point,
        public string $id,
        public ?string $parentId,
        public string $traceId,
        public string $domain,
        public string $origin,
        public ?string $profile,
        public array $stack,
        public array $context,
    ) {}

    /**
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
            'stack' => $this->stack,
            'context' => $this->context,
        ];
    }
}
