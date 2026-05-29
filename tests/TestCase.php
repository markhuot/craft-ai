<?php

namespace Tests;

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\migrations\Install;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase as PestTestCase;

class TestCase extends PestTestCase
{
    use RefreshesDatabase;

    protected function setUp(): void
    {
        static $migrated = false;
        if (! $migrated) {
            $migrated = true;
            $db = Craft::$app->getDb();
            foreach ([
                // Drop FK-child tables before the tables they reference —
                // craftai_comparisons has foreign keys onto craftai_artifacts
                // and craftai_sessions, and craftai_artifacts has one onto
                // craftai_sessions, so they have to go first.
                '{{%craftai_comparisons}}',
                '{{%craftai_artifacts}}',
                '{{%craftai_messages}}',
                '{{%craftai_sessions}}',
                '{{%craftai_preview_requests}}',
                '{{%craftai_comments}}',
            ] as $table) {
                if ($db->getSchema()->getTableSchema($table, true) !== null) {
                    $db->createCommand()->dropTable($table)->execute();
                }
            }

            $plugins = Craft::$app->getPlugins();
            if ($plugins->getPlugin('craft-ai') === null) {
                $plugins->installPlugin('craft-ai');
            } elseif ($db->getSchema()->getTableSchema('{{%craftai_messages}}', true) === null) {
                // Plugin is registered as installed but its tables were
                // dropped above without a matching uninstall — happens when
                // a previous test process installed, the install record
                // persisted across runs, and this process's drops left the
                // schema empty. Re-run the install migration manually so
                // each fresh test process gets a populated schema.
                $migration = new Install();
                $migration->db = $db;
                $migration->safeUp();
            }
        }

        parent::setUp();

        // Tool execution now goes through Craft permission checks. Default to
        // an admin identity so existing tests pass; tests that need to verify
        // permission denial can override the identity within the test body.
        $admin = new User();
        $admin->id = 1;
        $admin->admin = true;
        Craft::$app->getUser()->setIdentity($admin);

        // Default the shared ToolContext to the CP surface so tests model the
        // primary user-facing path (the in-app chat). Tests exercising the MCP
        // or widget surfaces can re-call begin() with a different ClientType.
        /** @var ToolContext $context */
        $context = Craft::$container->get(ToolContext::class);
        $context->begin('test-session', null, ClientType::CP);
    }
}
