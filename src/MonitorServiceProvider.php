<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;
use Kirschbaum\Monitor\Trace\Trace;

class MonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        $this->app->singleton(Monitor::class, fn (Container $app): Monitor => new Monitor($app));
        $this->app->singleton(Trace::class);
        $this->app->singleton(ControlStack::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->bind(Runner::class, LiveRunner::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitor.php' => config_path('monitor.php'),
        ], 'monitor-config');

        if ($this->app->runningInConsole() && $this->app->make('config')->get('monitor.trace.console', true)) {
            $this->app->make(Trace::class)->pickup();
        }
    }
}
