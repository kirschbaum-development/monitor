<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

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

    public function from(string|object $origin): MonitorWithOrigin
    {
        return new MonitorWithOrigin($origin);
    }

    /**
     * Create a controlled execution block.
     *
     * Supports fluent interface patterns:
     * - Monitor::controlled()->from('origin')->for('name')->run($callback)
     * - Monitor::controlled('name')->from('origin')->run($callback)
     */
    public function controlled(?string $name = null): Controlled
    {
        return $name !== null ? Controlled::for($name) : new Controlled;
    }
}
