<?php

namespace Tests;

use Craft;
use craft\elements\User;
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
            if ($db->getSchema()->getTableSchema('{{%craftai_messages}}', true) !== null) {
                $db->createCommand()->dropTable('{{%craftai_messages}}')->execute();
            }
            if ($db->getSchema()->getTableSchema('{{%craftai_sessions}}', true) !== null) {
                $db->createCommand()->dropTable('{{%craftai_sessions}}')->execute();
            }

            $plugins = Craft::$app->getPlugins();
            if ($plugins->getPlugin('craft-ai') === null) {
                $plugins->installPlugin('craft-ai');
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
    }
}
