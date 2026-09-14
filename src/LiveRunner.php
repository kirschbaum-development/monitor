<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Kirschbaum\Monitor\Trace\Trace;

final readonly class LiveRunner implements Runner
{
    public function __construct(
        private Container $container,
        private Dispatcher $events,
        private Trace $trace,
        private ControlStack $stack,
    ) {}

    public function execute(Control $control, Closure $callback): Outcome
    {
        return (new Run($control, $callback, $this->container, $this->events, $this->trace, $this->stack))->execute();
    }
}
