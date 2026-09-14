<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\Http;
use Kirschbaum\Monitor\MonitorServiceProvider;
use Kirschbaum\Redactor\RedactorServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        return [
            RedactorServiceProvider::class,
            McpServiceProvider::class,
            MonitorServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventingStrayRequests();
    }
}
