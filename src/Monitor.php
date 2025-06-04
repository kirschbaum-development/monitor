<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;

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

    /**
     * @param  array<string, mixed>  $context
     */
    public function ccp(string $name, ?Closure $callback = null, array $context = [], ?Closure $onFail = null): mixed
    {
        if (! $callback) {
            throw new \InvalidArgumentException('Callback is required for CCP blocks.');
        }

        return Ccp::run($name, $callback, $context, $onFail);
    }
}
