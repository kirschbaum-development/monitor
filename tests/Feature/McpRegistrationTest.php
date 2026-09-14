<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Mcp\Facades\Mcp;
use Tests\TestCase;

/**
 * The provider registers the MCP server only when it is enabled at boot.
 */
final class McpRegistrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('monitor.mcp.enabled', true);
        $app['config']->set('monitor.mcp.handle', 'monitor');
    }

    public function test_the_server_is_registered_as_a_local_server_when_enabled(): void
    {
        $this->assertNotNull(Mcp::getLocalServer('monitor'));
    }
}
