<?php

declare(strict_types=1);

namespace Kirschbaum\Monitor;

use Composer\InstalledVersions;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Kirschbaum\Monitor\Breaker\CircuitBreaker;
use Kirschbaum\Monitor\Console\Commands\ExplainCommand;
use Kirschbaum\Monitor\Console\Commands\MakeControlPointCommand;
use Kirschbaum\Monitor\Console\Commands\OutcomesCommand;
use Kirschbaum\Monitor\Console\Commands\PointsCommand;
use Kirschbaum\Monitor\Console\Commands\PruneCommand;
use Kirschbaum\Monitor\Contracts\Runner;
use Kirschbaum\Monitor\Http\HttpBreaker;
use Kirschbaum\Monitor\Http\Middleware\CheckBreakers;
use Kirschbaum\Monitor\Http\Middleware\StartTrace;
use Kirschbaum\Monitor\Mcp\MonitorServer;
use Kirschbaum\Monitor\Records\Recorder;
use Kirschbaum\Monitor\Store\OutcomeStore;
use Kirschbaum\Monitor\Store\StoreOutcomes;
use Kirschbaum\Monitor\Support\Profiles;
use Kirschbaum\Monitor\Trace\PicksUpCommandTrace;
use Kirschbaum\Monitor\Trace\PicksUpJobTrace;
use Kirschbaum\Monitor\Trace\PropagatesTrace;
use Kirschbaum\Monitor\Trace\Trace;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;

class MonitorServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        $this->registerServices();
    }

    /**
     * Bootstrap the package.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
            $this->registerAbout();
        }

        $this->registerMiddleware();
        $this->registerListeners();
        $this->registerMacros();
        $this->registerMcpServer();
    }

    /**
     * Bind the package's services into the container.
     */
    protected function registerServices(): void
    {
        $this->app->singleton(Monitor::class, fn (Container $app): Monitor => new Monitor($app));
        $this->app->singleton(Trace::class);
        $this->app->singleton(ControlStack::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(OutcomeStore::class);
        $this->app->singleton(StoreOutcomes::class);
        $this->app->bind(Runner::class, LiveRunner::class);
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/monitor.php' => config_path('monitor.php'),
        ], 'monitor-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'monitor-migrations');
    }

    /**
     * Register the package's console commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            PointsCommand::class,
            ExplainCommand::class,
            OutcomesCommand::class,
            PruneCommand::class,
            MakeControlPointCommand::class,
        ]);
    }

    /**
     * Register the package's section of `php artisan about`.
     */
    protected function registerAbout(): void
    {
        AboutCommand::add('Monitor', fn (): array => [
            'Version' => InstalledVersions::isInstalled('kirschbaum-development/monitor')
                ? InstalledVersions::getPrettyVersion('kirschbaum-development/monitor')
                : 'dev',
            'Outcome Store' => AboutCommand::format(config('monitor.records.store.enabled'), console: fn ($value): string => $value ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF'),
            'MCP Server' => AboutCommand::format(config('monitor.mcp.enabled'), console: fn ($value): string => $value ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF'),
            'Profiles' => implode(', ', Profiles::names()),
        ]);
    }

    /**
     * Register the route middleware aliases.
     */
    protected function registerMiddleware(): void
    {
        $this->callAfterResolving('router', function (Router $router): void {
            $router->aliasMiddleware('monitor.trace', StartTrace::class);
            $router->aliasMiddleware('monitor.breakers', CheckBreakers::class);
        });
    }

    /**
     * Listen for the framework events the package reacts to, and flush the
     * outcome store at the end of every request, command and job.
     */
    protected function registerListeners(): void
    {
        $this->callAfterResolving(Dispatcher::class, function (Dispatcher $events): void {
            $events->subscribe(Recorder::class);
            $events->subscribe(StoreOutcomes::class);
            $events->listen(JobProcessing::class, PicksUpJobTrace::class);
            $events->listen(CommandStarting::class, PicksUpCommandTrace::class);
        });

        $this->app->booted(function (): void {
            $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel, Application $app): void {
                $kernel->whenRequestLifecycleIsLongerThan(-1, function () use ($app): void {
                    $app->make(StoreOutcomes::class)->flush();
                });
            });

            $this->callAfterResolving(ConsoleKernel::class, function (ConsoleKernel $kernel, Application $app): void {
                $kernel->whenCommandLifecycleIsLongerThan(-1, function () use ($app): void {
                    $app->make(StoreOutcomes::class)->flush();
                });
            });
        });
    }

    /**
     * Register Http::traced(), Http::breaker() and their PendingRequest forms.
     */
    protected function registerMacros(): void
    {
        PropagatesTrace::register(fn (): Trace => $this->app->make(Trace::class));
        HttpBreaker::register(fn (): CircuitBreaker => $this->app->make(CircuitBreaker::class));
    }

    /**
     * Register the MCP server when laravel/mcp is installed and the server is enabled.
     */
    protected function registerMcpServer(): void
    {
        if (! class_exists(Server::class) || ! config('monitor.mcp.enabled', false)) {
            return;
        }

        $handle = config('monitor.mcp.handle', 'monitor');

        Mcp::local(is_string($handle) && $handle !== '' ? $handle : 'monitor', MonitorServer::class);
    }
}
