<?php

use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Command;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;

// Plugins::savePluginSettings + project-config sync writes through the
// `plugins` table, which the RefreshesDatabase trait rolls back between
// tests. The Yii module registry holds onto craft-ai across tests, but
// the project-config "applied" config gets blown away — so on test #2+
// the plugin's settings come back null. Re-install (idempotent) before
// each test so each one starts from a known good state.
beforeEach(function () {
    $plugins = \Craft::$app->getPlugins();
    if ($plugins->getPlugin('craft-ai') === null) {
        $plugins->installPlugin('craft-ai');
    }
});

/**
 * End-to-end check that plugin settings actually flow through
 * Craft's project-config pipeline. The interesting path is
 * Plugins::savePluginSettings() -> ProjectConfig::set('plugins.<handle>.settings')
 * -> Plugins::handleChangedPluginInfo() -> setSettings on the live plugin.
 *
 * If toArray() leaves something unserializable in the array, or if
 * setAutomations / setCommands silently lose data on the round-trip,
 * this test fails — which is the failure mode we'd otherwise only see
 * during a multi-env project-config sync in production.
 *
 * We don't introspect the raw ProjectConfig values directly because
 * Craft wraps associative rows in an internal `__assoc__` key to
 * preserve YAML ordering; that representation is intentionally opaque.
 * What we care about is "did the value survive a save + read".
 */

it('persists automations through Craft project config', function () {
    $plugin = Plugin::getInstance();
    $ok = \Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'automations' => [
            [
                'name' => 'review-drafts',
                'event' => Automation::EVENT_DRAFT_SAVED,
                'sectionHandle' => '',
                'prompt' => 'Review this draft for tone.',
                'enabled' => true,
            ],
        ],
    ]);
    expect($ok)->toBeTrue();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    $autos = $settings->getAutomations();
    expect($autos)->toHaveCount(1);
    expect($autos[0]->event)->toBe(Automation::EVENT_DRAFT_SAVED);
    expect($autos[0]->prompt)->toBe('Review this draft for tone.');

    // And the raw project config DOES hold a `commands`/`automations`
    // key (its exact internal shape is Craft's own — we just confirm the
    // value lives at the documented path, so a sync to YAML would carry
    // it across to the next env).
    $stored = \Craft::$app->getProjectConfig()->get('plugins.craft-ai.settings.automations');
    expect($stored)->toBeArray();
});

it('persists slash commands through Craft project config', function () {
    $plugin = Plugin::getInstance();
    $ok = \Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'commands' => [
            ['name' => 'translate', 'prompt' => 'Translate this entry.'],
            ['name' => 'editorial-review', 'prompt' => 'Review this entry.'],
        ],
    ]);
    expect($ok)->toBeTrue();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    $names = array_map(static fn (Command $c) => $c->name, $settings->getCommands());
    expect($names)->toBe(['translate', 'editorial-review']);

    $stored = \Craft::$app->getProjectConfig()->get('plugins.craft-ai.settings.commands');
    expect($stored)->toBeArray();
});

it('lets the plugin overwrite commands cleanly on a subsequent save', function () {
    $plugin = Plugin::getInstance();

    \Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'commands' => [['name' => 'first', 'prompt' => 'one']],
    ]);
    \Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'commands' => [['name' => 'second', 'prompt' => 'two']],
    ]);

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    $names = array_map(static fn (Command $c) => $c->name, $settings->getCommands());
    expect($names)->toBe(['second']);
});
