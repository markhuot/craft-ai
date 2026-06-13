<?php

use craft\elements\User;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Command;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;

beforeEach(function () {
    $plugins = Craft::$app->getPlugins();
    if ($plugins->getPlugin('craft-ai') === null) {
        $plugins->installPlugin('craft-ai');
    }

    // Plugin instance is a Yii singleton — its in-memory settings model
    // survives across tests even though RefreshesDatabase rolls back the
    // DB. Reset to the "never configured" sentinel so each test starts
    // from the same baseline (seeded defaults via Settings::getCommands).
    $plugin = Plugin::getInstance();
    $settings = $plugin->getSettings();
    if ($settings instanceof Settings) {
        $settings->setCommands(null);
        $settings->setAutomations([]);
    }

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    Craft::$app->getUser()->setIdentity($user);
});

function commandsBySettings(): array
{
    /** @var Settings $settings */
    $settings = Plugin::getInstance()->getSettings();
    return $settings->getCommands();
}

it('renders the new-command edit form', function () {
    $response = test()->get('admin/ai/commands/new');

    $response->assertOk();
    // The action input wires the form to the save action, which is the
    // contract that lets the edit page round-trip a save.
    $response->assertSee('craft-ai/commands/save');
    $response->assertSee('Slug-safe handle');
});

it('renders the edit form for a seeded default without saving anything first', function () {
    // The dedicated edit screen has to work on a fresh install where the
    // user hasn't saved anything yet — the settings page renders seeded
    // defaults (/translate, /editorial-review) and clicking through must
    // resolve. The seeded UIDs are hardcoded in Command::defaults() for
    // exactly this reason; previously fromArray would mint fresh UIDs on
    // every read and the link/lookup would disagree.
    $defaults = commandsBySettings();
    $translate = null;
    foreach ($defaults as $cmd) {
        if ($cmd->name === 'translate') {
            $translate = $cmd;
        }
    }
    expect($translate)->not->toBeNull();

    $response = test()->get('admin/ai/commands/'.$translate->uid);
    $response->assertOk();
    $response->assertSee('translate');
});

it('renders the edit form for an existing command', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'commands' => [
            ['name' => 'translate', 'prompt' => 'Translate this entry.'],
        ],
    ]);
    $uid = commandsBySettings()[0]->uid;

    $response = test()->get('admin/ai/commands/'.$uid);

    $response->assertOk();
    $response->assertSee('Translate this entry.');
});

it('404s on an unknown uid', function () {
    // The test runner re-throws controller exceptions by default rather
    // than converting them to a 404 response — mirror the catch pattern
    // the SessionsControllerTest uses for the same case.
    $threw = false;
    try {
        test()->get('admin/ai/commands/00000000-0000-0000-0000-000000000000');
    } catch (\yii\web\NotFoundHttpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('saves a new slash command and redirects back to the plugin settings page', function () {
    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/commands/save',
            'name' => 'audit',
            'prompt' => 'Run an SEO audit and report findings inline.',
            'enabled' => '1',
        ])
        ->send();

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toContain('settings/plugins/craft-ai');

    $names = array_map(fn (Command $c) => $c->name, commandsBySettings());
    expect($names)->toContain('audit');
});

it('updates an existing slash command in place rather than appending a duplicate', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'commands' => [
            ['name' => 'translate', 'prompt' => 'Original prompt.'],
        ],
    ]);
    $uid = commandsBySettings()[0]->uid;

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/commands/save',
            'uid' => $uid,
            'name' => 'translate',
            'prompt' => 'Rewritten prompt.',
            'enabled' => '1',
        ])
        ->send();

    $commands = commandsBySettings();
    expect($commands)->toHaveCount(1);
    expect($commands[0]->uid)->toBe($uid);
    expect($commands[0]->prompt)->toBe('Rewritten prompt.');
});

it('preserves existing automations across a single-command save', function () {
    // This is the project-config-replacement landmine: Craft writes
    // plugins.craft-ai.settings via ProjectConfig::set, which replaces
    // the whole subtree. If the controller didn't re-emit automations
    // when saving a command, this test would catch it.
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'automations' => [
            [
                'name' => 'review-drafts',
                'event' => Automation::EVENT_DRAFT_SAVED,
                'sectionHandle' => '',
                'prompt' => 'Review this draft for tone.',
                'enabled' => true,
            ],
        ],
        'commands' => [
            ['name' => 'translate', 'prompt' => 'Translate.'],
        ],
    ]);

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/commands/save',
            'name' => 'new-one',
            'prompt' => 'New command prompt.',
            'enabled' => '1',
        ])
        ->send();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    $autos = $settings->getAutomations();
    expect($autos)->toHaveCount(1);
    expect($autos[0]->prompt)->toBe('Review this draft for tone.');
});

it('re-renders the edit form on validation failure rather than persisting bad data', function () {
    $before = commandsBySettings();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/commands/save',
            'name' => 'compact', // reserved
            'prompt' => 'shadow the built-in /compact',
            'enabled' => '1',
        ])
        ->send();

    // A failed save renders the form template directly (200) instead of
    // redirecting, so the user keeps their in-flight edits.
    $response->assertOk();

    $afterNames = array_map(fn (Command $c) => $c->name, commandsBySettings());
    expect($afterNames)->not->toContain('compact');
    expect(count(commandsBySettings()))->toBe(count($before));
});

it('deletes a slash command and redirects back to the settings page', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'commands' => [
            ['name' => 'keepme',   'prompt' => 'still here'],
            ['name' => 'deleteme', 'prompt' => 'going away'],
        ],
    ]);

    $deleteMe = null;
    foreach (commandsBySettings() as $cmd) {
        if ($cmd->name === 'deleteme') {
            $deleteMe = $cmd;
        }
    }
    expect($deleteMe)->not->toBeNull();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/commands/delete',
            'uid' => $deleteMe->uid,
        ])
        ->send();

    $response->assertRedirect();

    $remaining = array_map(fn (Command $c) => $c->name, commandsBySettings());
    expect($remaining)->toBe(['keepme']);
});

it('preserves existing automations across a command delete', function () {
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'automations' => [
            [
                'name' => 'review-drafts',
                'event' => Automation::EVENT_DRAFT_SAVED,
                'sectionHandle' => '',
                'prompt' => 'Review.',
                'enabled' => true,
            ],
        ],
        'commands' => [
            ['name' => 'translate', 'prompt' => 'Translate.'],
        ],
    ]);
    $uid = commandsBySettings()[0]->uid;

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/commands/delete',
            'uid' => $uid,
        ])
        ->send();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    expect($settings->getAutomations())->toHaveCount(1);
});
