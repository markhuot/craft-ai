<?php

use craft\elements\User;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;

beforeEach(function () {
    $plugins = Craft::$app->getPlugins();
    if ($plugins->getPlugin('craft-ai') === null) {
        $plugins->installPlugin('craft-ai');
    }

    // The plugin instance is a Yii singleton — its in-memory settings
    // model survives across tests even though RefreshesDatabase rolls
    // back the DB. Reset to a clean baseline.
    $plugin = Plugin::getInstance();
    $settings = $plugin->getSettings();
    if ($settings instanceof Settings) {
        $settings->setAutomations([]);
        $settings->setCommands(null);
    }

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    Craft::$app->getUser()->setIdentity($user);
});

function automationsBySettings(): array
{
    /** @var Settings $settings */
    $settings = Plugin::getInstance()->getSettings();
    return $settings->getAutomations();
}

it('renders the new-automation edit form', function () {
    $response = test()->get('admin/ai/automations/new');

    $response->assertOk();
    // The action input wires the form to the save action, which is the
    // contract that lets the edit page round-trip a save.
    $response->assertSee('craft-ai/automations/save');
    $response->assertSee('Which Craft event');
});

it('renders the edit form for an existing automation', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
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
    $uid = automationsBySettings()[0]->uid;

    $response = test()->get('admin/ai/automations/'.$uid);

    $response->assertOk();
    $response->assertSee('Review this draft for tone.');
});

it('404s on an unknown uid', function () {
    $threw = false;
    try {
        test()->get('admin/ai/automations/00000000-0000-0000-0000-000000000000');
    } catch (\yii\web\NotFoundHttpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('saves a new automation and redirects back to the plugin settings page', function () {
    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/automations/save',
            'name' => 'review-drafts',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'sectionHandle' => '',
            'volumeHandle' => '',
            'prompt' => 'Review this draft.',
            'enabled' => '1',
        ])
        ->send();

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toContain('settings/plugins/craft-ai');

    $names = array_map(fn (Automation $a) => $a->name, automationsBySettings());
    expect($names)->toContain('review-drafts');
});

it('updates an existing automation in place rather than appending a duplicate', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'automations' => [
            [
                'name' => 'review-drafts',
                'event' => Automation::EVENT_DRAFT_SAVED,
                'prompt' => 'Original prompt.',
                'enabled' => true,
            ],
        ],
    ]);
    $uid = automationsBySettings()[0]->uid;

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/automations/save',
            'uid' => $uid,
            'name' => 'review-drafts',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'sectionHandle' => '',
            'volumeHandle' => '',
            'prompt' => 'Rewritten prompt.',
            'enabled' => '1',
        ])
        ->send();

    $automations = automationsBySettings();
    expect($automations)->toHaveCount(1);
    expect($automations[0]->uid)->toBe($uid);
    expect($automations[0]->prompt)->toBe('Rewritten prompt.');
});

it('preserves existing slash commands across a single-automation save', function () {
    // The project-config-replacement landmine — same as the inverse
    // test in CommandsControllerTest. If the controller didn't re-emit
    // commands when saving an automation, this test would catch it.
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'automations' => [
            [
                'name' => 'review',
                'event' => Automation::EVENT_DRAFT_SAVED,
                'prompt' => 'Old.',
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
            'action' => 'craft-ai/automations/save',
            'name' => 'new-rule',
            'event' => Automation::EVENT_ENTRY_SAVED,
            'prompt' => 'New automation prompt.',
            'enabled' => '1',
        ])
        ->send();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    $cmds = $settings->getCommands();
    $names = array_map(fn ($c) => $c->name, $cmds);
    expect($names)->toContain('translate');
});

it('re-renders the edit form on validation failure rather than persisting bad data', function () {
    $before = automationsBySettings();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/automations/save',
            'name' => 'invalid',
            'event' => 'not.a.real.event',
            'prompt' => 'p',
            'enabled' => '1',
        ])
        ->send();

    // A failed save renders the form template directly (200) instead of
    // redirecting, so the user keeps their in-flight edits.
    $response->assertOk();

    expect(count(automationsBySettings()))->toBe(count($before));
});

it('deletes an automation and redirects back to the settings page', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'automations' => [
            ['name' => 'keepme',   'event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'still here'],
            ['name' => 'deleteme', 'event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'going away'],
        ],
    ]);

    $deleteMe = null;
    foreach (automationsBySettings() as $auto) {
        if ($auto->name === 'deleteme') {
            $deleteMe = $auto;
        }
    }
    expect($deleteMe)->not->toBeNull();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/automations/delete',
            'uid' => $deleteMe->uid,
        ])
        ->send();

    $response->assertRedirect();

    $remaining = array_map(fn (Automation $a) => $a->name, automationsBySettings());
    expect($remaining)->toBe(['keepme']);
});

it('preserves existing slash commands across an automation delete', function () {
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'automations' => [
            ['name' => 'review', 'event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'p'],
        ],
        'commands' => [
            ['name' => 'translate', 'prompt' => 'Translate.'],
        ],
    ]);
    $uid = automationsBySettings()[0]->uid;

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/automations/delete',
            'uid' => $uid,
        ])
        ->send();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    $names = array_map(fn ($c) => $c->name, $settings->getCommands());
    expect($names)->toContain('translate');
});
