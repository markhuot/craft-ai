<?php

namespace Tests\Support;

use CraftCms\Cms\Database\Migrations\Install;
use CraftCms\Cms\Database\Migrator;
use CraftCms\Cms\Plugin\Testing\InstallsPlugin;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\Site\Data\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Builds the Craft 6 test database once, committed, before the suite runs.
 *
 * Unlike {@see \Tests\TestCase} this does NOT use LazilyRefreshDatabase, so the
 * migrate:fresh + Craft install + plugin install all commit (no per-test
 * transaction to roll them back). The per-test base then leaves the schema
 * untouched, so test transactions only ever wrap DML — which sidesteps the
 * "SAVEPOINT … does not exist" failure caused by running the plugin's DDL
 * install inside the per-test transaction.
 *
 * Mirrors {@see \CraftCms\Cms\Plugin\Testing\PluginTestCase::migrateDatabases()}.
 */
class SetupCase extends Orchestra
{
    use CraftSixHarness, InstallsPlugin {
        CraftSixHarness::bootPluginProvider insteadof InstallsPlugin;
    }

    /** Don't auto-install on setUp — build() drives the full sequence. */
    public function setupInstallsPlugin(): void
    {
        //
    }

    /** Don't delete the plugins registry on teardown — the suite needs it. */
    public function tearDownInstallsPlugin(): void
    {
        //
    }

    public function build(): void
    {
        // Register the plugin with Composer's installed list up front so the
        // Install's project-config validation sees craft-ai as installed.
        $this->composerInstallPlugin();

        // Clear any stale project config / compiled state from a prior build so
        // the Install doesn't validate against it (mirrors Craft's own seeder).
        Context::forgetHidden('craft.info');
        Context::forgetHidden('craft.isInstalled');
        File::cleanDirectory(config_path('craft/project'));
        File::cleanDirectory(storage_path('runtime/compiled_classes'));
        Cache::lock(ProjectConfig::MUTEX_NAME)->forceRelease();

        $this->artisan('migrate:fresh', ['--force' => true]);
        Schema::drop('migrations');

        $site = new Site([
            'name' => 'Craft test site',
            'handle' => 'default',
            'language' => 'en-US',
            'baseUrl' => 'https://localhost/',
            'primary' => true,
            'hasUrls' => true,
        ]);

        $migration = (new Install(
            username: 'craftcms',
            password: 'craftcms2018!!',
            email: 'support@craftcms.com',
            site: $site,
        ))->silent();

        $migrator = app(Migrator::class)->track('craft');
        $migrator->runMigration($migration, 'up');
        $migrator->getRepository()->log('Install', 1);

        foreach ($migrator->getPendingMigrations() as $file) {
            $migrator->getRepository()->log($migrator->getMigrationName($file), 1);
        }

        // Install + enable the plugin (DDL — committed here, never per test).
        $this->installAndEnablePlugin();
    }
}
