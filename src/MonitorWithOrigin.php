<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

class MonitorWithOrigin
{
    public function __construct(
        private readonly string|object $origin
    ) {}

    public function controlled(): Controlled
    {
        return (new Controlled)->from($this->origin);
    }

    public function log(): StructuredLogger
    {
        return StructuredLogger::from($this->origin);
    }
}
