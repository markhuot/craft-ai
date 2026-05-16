<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\services\AutomationDispatcher;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();
    Section::factory()->name('News')->handle('news')->create();

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    Craft::$app->getUser()->setIdentity($user);

    // Most tests want the dispatcher to actually fire, so clear the
    // shared ToolContext that TestCase::setUp primes. Tests that need
    // to verify the recursion guard re-prime it explicitly.
    /** @var ToolContext $ctx */
    $ctx = Craft::$container->get(ToolContext::class);
    $ctx->end();
});

function applySettings(array $automations): Settings
{
    $settings = new Settings();
    $settings->setAutomations($automations);

    // Inject the settings into the plugin instance so the dispatcher
    // sees them when it reads via Plugin::getInstance()->getSettings().
    $plugin = Plugin::getInstance();
    $reflection = new \ReflectionObject($plugin);
    $prop = $reflection->getProperty('settings');
    $prop->setAccessible(true);
    $prop->setValue($plugin, $settings);

    return $settings;
}

function queuedJobs(): array {
    $queue = Craft::$app->getQueue();
    $reflection = new \ReflectionObject($queue);
    if (! $reflection->hasMethod('getJobInfo')) {
        return [];
    }
    return $queue->getJobInfo();
}

it('dispatches an automation when the matching event fires', function () {
    applySettings([
        [
            'name' => 'Review drafts',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'sectionHandle' => '',
            'prompt' => 'Review this draft.',
            'enabled' => true,
        ],
    ]);

    $entry = Entry::factory()->section('posts')->title('Canonical')->create();
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    /** @var AutomationDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(AutomationDispatcher::class);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $draft);

    $sessions = SessionRecord::find()->all();
    expect($sessions)->toHaveCount(1);

    $session = $sessions[0];
    expect($session->title)->toContain('Review drafts');
    expect($session->userId)->toBe(1);

    $userMsg = MessageRecord::find()
        ->where(['sessionId' => $session->id, 'role' => 'user'])
        ->one();
    expect($userMsg)->not->toBeNull();
    expect($userMsg->content)->toContain('Review this draft');

    $sysMsg = MessageRecord::find()
        ->where(['sessionId' => $session->id, 'role' => 'system'])
        ->one();
    expect($sysMsg)->not->toBeNull();
    expect($sysMsg->content)->toContain('draftId: '.$draft->draftId);
});

it('skips disabled automations', function () {
    applySettings([
        [
            'event' => Automation::EVENT_DRAFT_SAVED,
            'prompt' => 'do not fire',
            'enabled' => false,
        ],
    ]);

    $entry = Entry::factory()->section('posts')->create();
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    /** @var AutomationDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(AutomationDispatcher::class);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $draft);

    expect(SessionRecord::find()->count())->toBe('0');
});

it('respects the section filter', function () {
    applySettings([
        [
            'event' => Automation::EVENT_DRAFT_SAVED,
            'sectionHandle' => 'news',
            'prompt' => 'news only',
            'enabled' => true,
        ],
    ]);

    $postsEntry = Entry::factory()->section('posts')->create();
    $postsDraft = Craft::$app->drafts->createDraft($postsEntry, 1);

    /** @var AutomationDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(AutomationDispatcher::class);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $postsDraft);

    expect(SessionRecord::find()->count())->toBe('0');

    $newsEntry = Entry::factory()->section('news')->create();
    $newsDraft = Craft::$app->drafts->createDraft($newsEntry, 1);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $newsDraft);

    expect(SessionRecord::find()->count())->toBe('1');
});

it('bails when invoked inside an active tool context (recursion guard)', function () {
    applySettings([
        [
            'event' => Automation::EVENT_DRAFT_SAVED,
            'prompt' => 'should be skipped',
            'enabled' => true,
        ],
    ]);

    // Simulate "we're inside an agent loop" by priming the shared context.
    /** @var ToolContext $ctx */
    $ctx = Craft::$container->get(ToolContext::class);
    $ctx->begin('agent-session', 'tu-x', ClientType::CP);

    $entry = Entry::factory()->section('posts')->create();
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    /** @var AutomationDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(AutomationDispatcher::class);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $draft);

    expect(SessionRecord::find()->count())->toBe('0');
});

it('matches the correct event key, ignoring unrelated rules', function () {
    applySettings([
        [
            'name' => 'Delete handler',
            'event' => Automation::EVENT_ENTRY_DELETED,
            'prompt' => 'on delete',
            'enabled' => true,
        ],
        [
            'name' => 'Draft handler',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'prompt' => 'on draft save',
            'enabled' => true,
        ],
    ]);

    $entry = Entry::factory()->section('posts')->create();
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    /** @var AutomationDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(AutomationDispatcher::class);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $draft);

    $sessions = SessionRecord::find()->all();
    expect($sessions)->toHaveCount(1);
    expect($sessions[0]->title)->toContain('Draft handler');
});

it('creates one session per matching rule when several apply', function () {
    applySettings([
        [
            'name' => 'Rule A',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'prompt' => 'A prompt',
            'enabled' => true,
        ],
        [
            'name' => 'Rule B',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'prompt' => 'B prompt',
            'enabled' => true,
        ],
    ]);

    $entry = Entry::factory()->section('posts')->create();
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    /** @var AutomationDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(AutomationDispatcher::class);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $draft);

    $titles = array_map(static fn ($s) => $s->title, SessionRecord::find()->all());
    expect($titles)->toHaveCount(2);
    $joined = implode('|', $titles);
    expect($joined)->toContain('Rule A');
    expect($joined)->toContain('Rule B');
});
