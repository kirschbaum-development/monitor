<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor\Trace;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Config;

/**
 * Start a trace when an artisan command starts, so every control point and
 * every log line the command writes share one id. Off with trace.console.
 */
class PicksUpCommandTrace
{
    public function __construct(protected Trace $trace) {}

    public function handle(CommandStarting $event): void
    {
        if (! Config::boolean('monitor.trace.console', true)) {
            return;
        }

        $this->trace->pickup();
    }
}
