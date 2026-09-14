<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Tests\TestCase;

/**
 * The provider only loads the migration when the store is enabled at boot.
 */
final class StoreBootTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('monitor.records.store.enabled', true);
    }

    public function test_the_migration_path_is_registered_when_the_store_is_enabled(): void
    {
        $paths = $this->app->make(Migrator::class)->paths();

        $this->assertNotEmpty(array_filter($paths, fn (string $p): bool => str_ends_with($p, 'database/migrations')));
    }
}
