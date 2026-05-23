<?php

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use markhuot\craftai\tools\ToolOutput;

uses(Tests\TestCase::class)->in('./');

function decode(ToolOutput $output): array
{
    return json_decode($output->text, true);
}

function writeTemplate(string $base, string $relative, string $contents): void
{
    $path = $base.'/'.ltrim($relative, '/');
    FileHelper::createDirectory(dirname($path));
    file_put_contents($path, $contents);
}

/**
 * Insert a non-admin user via direct SQL and return its element ID. Used in
 * cross-user authorization tests where we need a real foreign userId on a
 * SessionRecord/CommentRecord but don't want the cost of running the full
 * Craft user save pipeline. Each call uses a fresh random suffix so multiple
 * users can coexist in a single test.
 */
function createOtherUser(string $labelPrefix = 'other'): int
{
    $suffix = bin2hex(random_bytes(4));
    $db = Craft::$app->getDb();
    $elementsTable = $db->getSchema()->getRawTableName('{{%elements}}');
    $usersTable = $db->getSchema()->getRawTableName('{{%users}}');

    $db->createCommand()->insert($elementsTable, [
        'type' => User::class,
        'enabled' => true,
        'archived' => false,
        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        'uid' => StringHelper::UUID(),
    ])->execute();
    $otherId = (int) $db->getLastInsertID();

    $db->createCommand()->insert($usersTable, [
        'id' => $otherId,
        'username' => $labelPrefix.'-'.$suffix,
        'email' => $labelPrefix.'-'.$suffix.'@example.com',
        'active' => true,
        'pending' => false,
        'locked' => false,
        'suspended' => false,
        'admin' => false,
        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
    ])->execute();

    return $otherId;
}
