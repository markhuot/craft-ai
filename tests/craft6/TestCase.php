<?php

namespace Tests;

use Craft;
use craft\elements\User;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use Tests\Support\CraftSixHarness;

/**
 * Base test case for the craft-ai plugin under Craft 6.
 *
 * Extends Craft's first-party {@see PluginTestCase} (Orchestra Testbench +
 * LazilyRefreshDatabase + the InstallsPlugin trait). The database is built once
 * and committed by bin/craft6-setup.php before the suite runs, so this base
 * skips all per-test schema work (see migrateDatabases() / setupInstallsPlugin())
 * — each test's transaction then only wraps DML and rolls back cleanly.
 */
class TestCase extends PluginTestCase
{
    use CraftSixHarness;

    /**
     * No-op: the schema is pre-built (committed) by bin/craft6-setup.php.
     * Running migrate:fresh + the plugin's DDL install here would execute
     * inside LazilyRefreshDatabase's per-test transaction and break its
     * savepoint ("SAVEPOINT … does not exist").
     */
    protected function migrateDatabases(): void
    {
        //
    }

    /**
     * Per test, only register + boot the (already-installed) plugin. Never
     * re-run its install migration — that's DDL and would break the per-test
     * transaction (see migrateDatabases()).
     */
    public function setupInstallsPlugin(): void
    {
        $this->composerInstallPlugin();
        $this->bootPluginProvider();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tool execution runs Craft permission checks, so default to an admin
        // identity (the user the install created). Tests verifying permission
        // denial can override the identity in the test body.
        $admin = User::find()->admin()->one();
        $this->loginCraftUser((int) $admin->id);

        // Default the shared ToolContext to the CP surface, mirroring the
        // primary in-app chat path.
        Craft::$container->get(ToolContext::class)->begin('test-session', null, ClientType::CP);
    }
}
