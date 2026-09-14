<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider;
use Kirschbaum\Monitor\MonitorServiceProvider;
use Tests\TestCase;

/**
 * The store migration is published, never loaded behind the application's back.
 */
final class StoreBootTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('monitor.records.store.enabled', true);
    }

    public function test_the_migration_is_publishable_but_not_auto_loaded(): void
    {
        $paths = $this->app->make(Migrator::class)->paths();

        $this->assertNotContains(realpath(__DIR__.'/../../database/migrations'), array_map(realpath(...), $paths));

        $published = ServiceProvider::pathsToPublish(MonitorServiceProvider::class, 'monitor-migrations');

        $this->assertNotEmpty($published);
        $this->assertSame(realpath(__DIR__.'/../../database/migrations'), realpath((string) array_key_first($published)));
        $this->assertFileExists(__DIR__.'/../../database/migrations/2026_09_14_000000_create_monitor_outcomes_table.php');
    }
}
