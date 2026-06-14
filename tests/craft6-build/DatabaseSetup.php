<?php

use Tests\Support\SetupCase;

/**
 * One-time database build. Run explicitly by bin/craft6-tests.php before the
 * suite (it lives outside the *Test.php glob so it never runs as a normal
 * test). Installs Craft + the plugin into the test database, committed.
 */
uses(SetupCase::class);

it('builds the craft 6 test database', function () {
    $this->build();

    expect(\Craft::$app->getIsInstalled())->toBeTrue();
    expect(\Craft::$app->getPlugins()->isPluginInstalled('craft-ai'))->toBeTrue();
});
