<?php

namespace Tests;

use Craft;
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
            $schema = Craft::$app->getDb()->getSchema()->getTableSchema('{{%craftai_messages}}', true);
            if ($schema === null) {
                (new Install())->safeUp();
            }
        }

        parent::setUp();
    }
}
