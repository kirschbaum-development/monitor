<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Kirschbaum\Monitor\Support\LogRedactor;

class Monitor
{
    public function trace(): Trace
    {
        return app(Trace::class);
    }

    public function log(string|object $origin = 'Monitor'): StructuredLogger
    {
        return StructuredLogger::from($origin);
    }

    public function time(): LogTimer
    {
        return app(LogTimer::class);
    }

    public function breaker(): CircuitBreaker
    {
        return app(CircuitBreaker::class);
    }

    public function controlled(string $name, string|object|null $origin = null): Controlled
    {
        return Controlled::for($name, $origin);
    }

    public function redactor(): LogRedactor
    {
        return app(LogRedactor::class);
    }
}
