<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;
use Kirschbaum\Monitor\Console\ExplainCommand;
use Kirschbaum\Monitor\Console\MakeControlPointCommand;
use Kirschbaum\Monitor\Console\OutcomesCommand;
use Kirschbaum\Monitor\Console\PointsCommand;
use Kirschbaum\Monitor\Console\PruneCommand;
use Kirschbaum\Monitor\Mcp\MonitorServer;
use Kirschbaum\Monitor\Records\Recorder;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Kirschbaum\Monitor\Store\StoreOutcomes;
use Kirschbaum\Monitor\Trace\PicksUpJobTrace;
use Kirschbaum\Monitor\Trace\PropagatesTrace;
use Kirschbaum\Monitor\Trace\Trace;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;

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
        $this->app->singleton(OutcomeStore::class);
        $this->app->singleton(StoreOutcomes::class);
    }

    /**
     * Register the MCP server when laravel/mcp is installed and the server is enabled.
     */
    public static function registerMcpServer(Container $app): bool
    {
        $config = $app->make('config');

        if (! class_exists(Server::class) || ! $config->get('monitor.mcp.enabled', false)) {
            return false;
        }

        $handle = $config->get('monitor.mcp.handle', 'monitor');

        Mcp::local(is_string($handle) && $handle !== '' ? $handle : 'monitor', MonitorServer::class);

        return true;
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitor.php' => config_path('monitor.php'),
        ], 'monitor-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_monitor_outcomes_table.php' => database_path('migrations/'.date('Y_m_d_His').'_create_monitor_outcomes_table.php'),
        ], 'monitor-migrations');

        if ($this->app->make('config')->get('monitor.records.store.enabled', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->app->make('events')->subscribe(Recorder::class);
        $this->app->make('events')->subscribe(StoreOutcomes::class);

        if ($this->app->runningInConsole()) {
            $this->commands([OutcomesCommand::class, PruneCommand::class, PointsCommand::class, ExplainCommand::class, MakeControlPointCommand::class]);
        }
        $this->app->make('events')->listen(JobProcessing::class, PicksUpJobTrace::class);

        PropagatesTrace::register($this->app->make(Trace::class));

        self::registerMcpServer($this->app);

        if ($this->app->runningInConsole() && $this->app->make('config')->get('monitor.trace.console', true)) {
            $this->app->make(Trace::class)->pickup();
        }
    }
}
