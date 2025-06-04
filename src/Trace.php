<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Support\Str;

class Trace
{
    private ?string $traceId = null;

    public function pickup(?string $traceId = null): static
    {
        if ($this->hasStarted()) {
            return $this;
        }

        if ($traceId !== null) {
            $this->override($traceId);
        } else {
            $this->start();
        }

        return $this;
    }

    public function start(): void
    {
        if ($this->hasStarted()) {
            throw new \LogicException('Trace has already been started.');
        }

        $this->traceId = Str::uuid()->toString();
    }

    public function override(string $uuid): void
    {
        $this->traceId = $uuid;
    }

    public function id(): string
    {
        if (! $this->traceId) {
            throw new \LogicException('Trace ID has not been started.');
        }

        return $this->traceId;
    }

    public function hasStarted(): bool
    {
        return $this->traceId !== null;
    }

    public function hasNotStarted(): bool
    {
        return $this->traceId === null;
    }
}
