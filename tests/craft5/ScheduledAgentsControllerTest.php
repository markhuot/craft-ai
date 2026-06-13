<?php

use craft\elements\User;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\ScheduledAgent;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use markhuot\craftai\records\ScheduledRunRecord;

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
        $settings->setScheduledAgents([]);
        $settings->setCommands(null);
    }

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    Craft::$app->getUser()->setIdentity($user);
});

function scheduledAgentsBySettings(): array
{
    /** @var Settings $settings */
    $settings = Plugin::getInstance()->getSettings();
    return $settings->getScheduledAgents();
}

it('renders the new-scheduled-agent edit form', function () {
    $response = test()->get('admin/ai/scheduled-agents/new');

    $response->assertOk();
    // The action input wires the form to the save action, which is the
    // contract that lets the edit page round-trip a save.
    $response->assertSee('craft-ai/scheduled-agents/save');
    $response->assertSee('How often this agent should run');
});

it('renders the edit form for an existing scheduled agent', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'scheduledAgents' => [
            [
                'name' => 'weekly-roundup',
                'prompt' => 'Create a post about the latest advancements in LLM technology.',
                'frequency' => 'weekly',
                'time' => '09:00',
                'dayOfWeek' => 1,
                'userId' => 1,
            ],
        ],
    ]);
    $uid = scheduledAgentsBySettings()[0]->uid;

    $response = test()->get('admin/ai/scheduled-agents/'.$uid);

    $response->assertOk();
    $response->assertSee('Create a post about the latest advancements in LLM technology.');
});

it('404s on an unknown scheduled-agent uid', function () {
    $threw = false;
    try {
        test()->get('admin/ai/scheduled-agents/00000000-0000-0000-0000-000000000000');
    } catch (\yii\web\NotFoundHttpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('saves a new scheduled agent, stamping the creating admin as run-as user', function () {
    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/scheduled-agents/save',
            'name' => 'weekly-roundup',
            'frequency' => 'weekly',
            'time' => '09:00',
            'dayOfWeek' => '1',
            'prompt' => 'Create a post about LLM news.',
            'enabled' => '1',
        ])
        ->send();

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toContain('settings/plugins/craft-ai');

    $agents = scheduledAgentsBySettings();
    expect($agents)->toHaveCount(1);
    expect($agents[0]->name)->toBe('weekly-roundup');
    expect($agents[0]->frequency)->toBe('weekly');
    // The run-as identity is stamped server-side from the logged-in
    // admin, never read from the form.
    expect($agents[0]->userId)->toBe(1);
});

it('updates an existing scheduled agent in place, preserving the creator', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'scheduledAgents' => [
            [
                'name' => 'weekly-roundup',
                'prompt' => 'Original prompt.',
                'frequency' => 'weekly',
                'time' => '09:00',
                'dayOfWeek' => 1,
                'userId' => 1,
            ],
        ],
    ]);
    $uid = scheduledAgentsBySettings()[0]->uid;

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/scheduled-agents/save',
            'uid' => $uid,
            'name' => 'weekly-roundup',
            'frequency' => 'daily',
            'time' => '08:00',
            'prompt' => 'Rewritten prompt.',
            'enabled' => '1',
        ])
        ->send();

    $agents = scheduledAgentsBySettings();
    expect($agents)->toHaveCount(1);
    expect($agents[0]->uid)->toBe($uid);
    expect($agents[0]->prompt)->toBe('Rewritten prompt.');
    expect($agents[0]->frequency)->toBe('daily');
    expect($agents[0]->userId)->toBe(1);
});

it('clears the staged pending slot on save so the schedule recomputes', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'scheduledAgents' => [
            ['name' => 'r', 'prompt' => 'p', 'frequency' => 'daily', 'time' => '09:00', 'userId' => 1],
        ],
    ]);
    $uid = scheduledAgentsBySettings()[0]->uid;

    $stale = new ScheduledRunRecord();
    $stale->scheduledAgentUid = $uid;
    $stale->scheduledFor = '2026-06-05 09:00:00';
    $stale->status = ScheduledRunRecord::STATUS_PENDING;
    $stale->save();

    $history = new ScheduledRunRecord();
    $history->scheduledAgentUid = $uid;
    $history->scheduledFor = '2026-06-04 09:00:00';
    $history->status = ScheduledRunRecord::STATUS_QUEUED;
    $history->save();

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/scheduled-agents/save',
            'uid' => $uid,
            'name' => 'r',
            'frequency' => 'daily',
            'time' => '10:00',
            'prompt' => 'p',
            'enabled' => '1',
        ])
        ->send();

    // The stale precomputed slot is gone; the run history is preserved.
    expect(ScheduledRunRecord::find()->where(['scheduledAgentUid' => $uid, 'status' => ScheduledRunRecord::STATUS_PENDING])->all())->toHaveCount(0);
    expect(ScheduledRunRecord::find()->where(['scheduledAgentUid' => $uid, 'status' => ScheduledRunRecord::STATUS_QUEUED])->all())->toHaveCount(1);
});

it('preserves automations and slash commands across a scheduled-agent save', function () {
    // The project-config-replacement landmine — Craft writes the whole
    // settings subtree on save, so the controller must re-emit the other
    // two sections or they'd be wiped.
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'automations' => [
            ['name' => 'review', 'event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'Review.', 'enabled' => true],
        ],
        'commands' => [
            ['name' => 'translate', 'prompt' => 'Translate.'],
        ],
    ]);

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/scheduled-agents/save',
            'name' => 'new-schedule',
            'frequency' => 'daily',
            'time' => '09:00',
            'prompt' => 'Scheduled prompt.',
            'enabled' => '1',
        ])
        ->send();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    expect(array_map(fn ($a) => $a->name, $settings->getAutomations()))->toContain('review');
    expect(array_map(fn ($c) => $c->name, $settings->getCommands()))->toContain('translate');
    expect(array_map(fn ($s) => $s->name, $settings->getScheduledAgents()))->toContain('new-schedule');
});

it('preserves scheduled agents across an automation save', function () {
    // The inverse landmine: the automations controller must re-emit
    // scheduledAgents when it saves its own half.
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'scheduledAgents' => [
            ['name' => 'weekly-roundup', 'prompt' => 'p', 'frequency' => 'weekly', 'time' => '09:00', 'dayOfWeek' => 1, 'userId' => 1],
        ],
    ]);

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/automations/save',
            'name' => 'new-rule',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'prompt' => 'Automation prompt.',
            'enabled' => '1',
        ])
        ->send();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    expect(array_map(fn ($s) => $s->name, $settings->getScheduledAgents()))->toContain('weekly-roundup');
});

it('preserves scheduled agents across a slash-command save', function () {
    $plugin = Plugin::getInstance();
    Craft::$app->getPlugins()->savePluginSettings($plugin, [
        'scheduledAgents' => [
            ['name' => 'weekly-roundup', 'prompt' => 'p', 'frequency' => 'weekly', 'time' => '09:00', 'dayOfWeek' => 1, 'userId' => 1],
        ],
    ]);

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/commands/save',
            'name' => 'summarize',
            'prompt' => 'Summarize {args}.',
            'enabled' => '1',
        ])
        ->send();

    /** @var Settings $settings */
    $settings = $plugin->getSettings();
    expect(array_map(fn ($s) => $s->name, $settings->getScheduledAgents()))->toContain('weekly-roundup');
});

it('re-renders the edit form on validation failure rather than persisting bad data', function () {
    $before = scheduledAgentsBySettings();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/scheduled-agents/save',
            'name' => 'invalid',
            'frequency' => 'custom',
            'cronExpression' => 'definitely not cron',
            'prompt' => 'p',
            'enabled' => '1',
        ])
        ->send();

    // A failed save renders the form template directly (200) instead of
    // redirecting, so the user keeps their in-flight edits.
    $response->assertOk();

    expect(count(scheduledAgentsBySettings()))->toBe(count($before));
});

it('deletes a scheduled agent along with its run state and history', function () {
    Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), [
        'scheduledAgents' => [
            ['name' => 'keepme', 'prompt' => 'still here', 'frequency' => 'daily', 'time' => '09:00', 'userId' => 1],
            ['name' => 'deleteme', 'prompt' => 'going away', 'frequency' => 'daily', 'time' => '09:00', 'userId' => 1],
        ],
    ]);

    $deleteMe = null;
    foreach (scheduledAgentsBySettings() as $agent) {
        if ($agent->name === 'deleteme') {
            $deleteMe = $agent;
        }
    }
    expect($deleteMe)->not->toBeNull();

    $run = new ScheduledRunRecord();
    $run->scheduledAgentUid = $deleteMe->uid;
    $run->scheduledFor = '2026-06-04 09:00:00';
    $run->status = ScheduledRunRecord::STATUS_QUEUED;
    $run->save();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/scheduled-agents/delete',
            'uid' => $deleteMe->uid,
        ])
        ->send();

    $response->assertRedirect();

    $remaining = array_map(fn (ScheduledAgent $a) => $a->name, scheduledAgentsBySettings());
    expect($remaining)->toBe(['keepme']);
    expect(ScheduledRunRecord::find()->where(['scheduledAgentUid' => $deleteMe->uid])->all())->toHaveCount(0);
});
