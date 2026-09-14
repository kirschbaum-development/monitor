<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;
use Kirschbaum\Monitor\Logging\StructuredLogger;
use Kirschbaum\Monitor\Trace\Trace;

class Monitor
{
    public function __construct(protected Container $container) {}

    /**
     * Declare a control point.
     */
    public function control(string|BackedEnum $name, string|object|null $origin = null): Control
    {
        return new Control($name, $origin);
    }

    /**
     * The control points currently executing.
     */
    public function stack(): ControlStack
    {
        return $this->container->make(ControlStack::class);
    }

    public function trace(): Trace
    {
        return $this->container->make(Trace::class);
    }

    public function breaker(): CircuitBreaker
    {
        return $this->container->make(CircuitBreaker::class);
    }

    /**
     * A PSR-3 logger bound to an origin, so its records carry origin and domain.
     */
    public function log(string|object|null $origin = null): StructuredLogger
    {
        return new StructuredLogger($origin ?? $this->stack()->currentOrigin() ?? Monitor::class);
    }
}
