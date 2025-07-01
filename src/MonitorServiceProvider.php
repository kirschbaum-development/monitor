<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Support\ServiceProvider;
use Kirschbaum\Monitor\Support\ControlledContext;

class MonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        $this->app->singleton(Monitor::class, fn () => new Monitor);
        $this->app->singleton(Trace::class, fn () => new Trace);
        $this->app->singleton(LogTimer::class, fn () => new LogTimer);
        $this->app->singleton(CircuitBreaker::class, fn () => new CircuitBreaker);
        $this->app->singleton(ControlledContext::class, fn () => new ControlledContext);

        // Remove automatic logging channel merging - users should configure their own channels
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitor.php' => config_path('monitor.php'),
        ], 'monitor-config');

        $this->publishes([
            __DIR__.'/../config/logging-monitor.php' => config_path('logging-monitor.php'),
        ], 'monitor-logging');

        // Console auto-trace logic using configurable settings
        $consoleAutoTraceEnabled = config('monitor.console_auto_trace.enabled', true);
        $enableInTesting = config('monitor.console_auto_trace.enable_in_testing', false);

        $shouldAutoTrace = $consoleAutoTraceEnabled
            && $this->app->runningInConsole()
            && ($enableInTesting || ! $this->app->environment('testing'))
            && ! app(Trace::class)->hasStarted();

        if ($shouldAutoTrace) {
            app(Trace::class)->start();
        }
    }
}
