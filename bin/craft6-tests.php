<?php

/**
 * Runs the Craft 6 Pest suite: builds the database once (committed), then runs
 * each test file in its own process.
 *
 * Two alpha-era constraints drive this design:
 *
 *  1. The plugin's install is a Yii-style DDL migration (CREATE TABLE /
 *     addForeignKey → implicit COMMIT). If it runs inside LazilyRefreshDatabase's
 *     per-test transaction it destroys the savepoint ("SAVEPOINT … does not
 *     exist"). So we install the schema ONCE up front (Support/DatabaseSetup,
 *     which doesn't use a transaction) and the per-test base leaves it alone —
 *     each test's transaction then only wraps DML and rolls back cleanly.
 *
 *  2. The legacy Craft::$app is a long-lived process singleton whose Sites
 *     cache gets poisoned across a single many-file run. So each test file runs
 *     in its own process.
 *
 * File path arguments override the default glob (run a subset, e.g.
 * `php bin/craft6-tests.php tests/craft6/GetSectionsTest.php`).
 */

$php = PHP_BINARY;
$root = dirname(__DIR__);
chdir($root);

$pest = fn (string $file): string => sprintf(
    '%s vendor/bin/pest -c phpunit.craft6.xml --test-directory=tests/craft6 %s',
    escapeshellarg($php),
    escapeshellarg($file),
);

// 1. Ensure the test database exists (matches phpunit.craft6.xml).
$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$pass = '';
$database = 'craftai_test6';
try {
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not ensure database `$database`: {$e->getMessage()}\n");
    exit(1);
}

// The committed builder, run before each file. It lives outside tests/craft6
// so Pest's uses(TestCase)->in() doesn't bind it.
$build = sprintf(
    '%s vendor/bin/pest -c phpunit.craft6.xml %s',
    escapeshellarg($php),
    escapeshellarg('tests/craft6-build/DatabaseSetup.php'),
);

// 2. Run each test file in its own process, rebuilding the database (committed)
//    before each. The plugin's DDL install / project-config writes commit and
//    escape the per-test transaction, so a destructive test would otherwise
//    poison the shared database for every later file. A fresh build per file
//    keeps them isolated.
$files = array_slice($argv, 1);
if ($files === []) {
    $files = glob('tests/craft6/*Test.php');
}
sort($files);

$failed = [];
foreach ($files as $file) {
    passthru($build, $code);
    if ($code !== 0) {
        fwrite(STDERR, "\nDatabase setup failed before {$file}; aborting.\n");
        exit(1);
    }

    passthru($pest($file), $code);
    if ($code !== 0) {
        $failed[] = $file;
    }
}

echo "\n".str_repeat('=', 70)."\n";
if ($failed === []) {
    echo sprintf("Craft 6 suite: all %d files passed.\n", count($files));
    exit(0);
}

echo sprintf("Craft 6 suite: %d of %d files failed:\n", count($failed), count($files));
foreach ($failed as $file) {
    echo "  - $file\n";
}
exit(1);
