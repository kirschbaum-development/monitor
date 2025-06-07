<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Support;

use Kirschbaum\Monitor\Exceptions\NestedControlledBlockException;

final class ControlledContext
{
    /** @var array{name: string, uuid: string}|null */
    protected ?array $current = null;

    public function enter(string $name, string $uuid): void
    {
        if ($this->current !== null) {
            throw new NestedControlledBlockException(
                "Nested Controlled block detected: attempted to start '{$name}' while already in '{$this->current['name']}' (uuid: {$this->current['uuid']})."
            );
        }

        $this->current = compact('name', 'uuid');
    }

    public function exit(): void
    {
        $this->current = null;
    }

    public function isInside(): bool
    {
        return $this->current !== null;
    }

    /** @return array{name: string, uuid: string}|null */
    public function current(): ?array
    {
        return $this->current;
    }
}
