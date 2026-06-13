<?php

/**
 * Runs the Craft 6 Pest suite one test file per process.
 *
 * Craft 6 is still an alpha and runs through craftcms/yii2-adapter, whose
 * legacy Craft::$app is a long-lived process singleton. Across a single
 * many-file Pest process its in-memory service caches (sites in particular)
 * drift out of sync with the per-test database transaction rollback and
 * eventually break site resolution for every later test. Until that settles
 * upstream, isolate each test file in its own process so no cross-file state
 * leaks. Individual files still share one process (fast enough), and the suite
 * stays deterministic.
 *
 * Any file path arguments override the default glob (handy for running a
 * subset, e.g. `php bin/craft6-tests.php tests/craft6/GetSectionsTest.php`).
 */

$php = PHP_BINARY;
$root = dirname(__DIR__);
chdir($root);

$files = array_slice($argv, 1);
if ($files === []) {
    $files = glob('tests/craft6/*Test.php');
}
sort($files);

$failed = [];
foreach ($files as $file) {
    $cmd = sprintf(
        '%s vendor/bin/pest -c phpunit.craft6.xml --test-directory=tests/craft6 %s',
        escapeshellarg($php),
        escapeshellarg($file),
    );
    passthru($cmd, $code);
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
