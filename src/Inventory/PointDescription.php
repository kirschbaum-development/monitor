<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Inventory;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One control point as the inventory sees it. Class-form points carry their
 * whole contract; inline points carry only what a name scan can know.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PointDescription implements Arrayable, JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  $policies
     * @param  list<array<string, mixed>>  $limits
     * @param  list<string>  $risks
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $name,
        public string $form,
        public string $origin,
        public string $domain,
        public ?string $file,
        public ?int $line,
        public ?string $profile = null,
        public array $policies = [],
        public array $limits = [],
        public array $risks = [],
        public bool $catchAll = false,
        public ?string $escalation = null,
        public array $notes = [],
        public bool $dynamicName = false,
        public bool $unreadable = false,
    ) {}

    public function isClassForm(): bool
    {
        return $this->form === 'class';
    }

    public function hasEscalation(): bool
    {
        return $this->escalation !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'point' => $this->name,
            'form' => $this->form,
            'origin' => $this->origin,
            'domain' => $this->domain,
            'file' => $this->file,
            'line' => $this->line,
            'profile' => $this->profile,
            'policies' => $this->policies,
            'limits' => $this->limits,
            'risks' => $this->risks,
            'catch_all' => $this->catchAll,
            'escalation' => $this->escalation,
            'dynamic_name' => $this->dynamicName,
            'unreadable' => $this->unreadable,
            'notes' => $this->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
