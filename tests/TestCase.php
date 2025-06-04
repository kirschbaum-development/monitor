<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kirschbaum\Monitor\MonitorServiceProvider;
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
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventingStrayRequests();
    }

    /**
     * Set up Log facade mocking to handle both explicit expectations and channel() calls.
     * Call this at the beginning of tests that need to mock Log methods.
     */
    protected function setupLogMocking(): void
    {
        // Allow channel() calls that may happen during Carbon/exception handling
        Log::shouldReceive('channel')
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
