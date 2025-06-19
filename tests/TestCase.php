<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\MonitorServiceProvider;
use Kirschbaum\Redactor\RedactorServiceProvider;
use Mockery\MockInterface;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [
            MonitorServiceProvider::class,
            RedactorServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventingStrayRequests();
    }

    /**
     * Set up Log facade mocking to handle Laravel infrastructure calls without
     * interfering with explicit test expectations for application logging.
     */
    protected function setupLogMocking(): void
    {
        // Only mock the methods that Laravel's infrastructure may call in background
        // during exception/deprecation handling, class loading, etc.
        // These are NOT the methods we want to test explicitly in our application code
        Log::shouldReceive('channel')
            ->andReturnSelf()
            ->byDefault();

        // Laravel's HandleExceptions calls warning() for deprecation notices
        Log::shouldReceive('warning')
            ->andReturnSelf()
            ->byDefault();
    }

    /**
     * Force bind a mock into the container for app() calls with parameters.
     */
    protected function mockBind(string $abstract, ?Closure $callback = null): MockInterface
    {
        $mock = $this->mock($abstract, $callback);

        app()->offsetSet($abstract, $mock);

        return $mock;
    }
}
