<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Kirschbaum\Monitor\Exceptions\NestedControlledBlockException;

final class ControlledContext
{
    /** @var array{name: string, ulid: string}|null */
    protected ?array $current = null;

    public function enter(string $name, string $ulid): void
    {
        if ($this->current !== null) {
            throw new NestedControlledBlockException(
                "Nested Controlled block detected: attempted to start '{$name}' while already in '{$this->current['name']}' (ULID: {$this->current['ulid']})."
            );
        }

        $this->current = compact('name', 'ulid');
    }

    public function exit(): void
    {
        $this->current = null;
    }

    public function isInside(): bool
    {
        return $this->current !== null;
    }

    /** @return array{name: string, ulid: string}|null */
    public function current(): ?array
    {
        return $this->current;
    }
}
