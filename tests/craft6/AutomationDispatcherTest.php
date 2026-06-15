<?php

use craft\elements\Asset;
use craft\elements\User;
use Craft;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\services\AutomationDispatcher;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;

if (! function_exists('seedImageVolume')) {
    /**
     * Create a Local filesystem backed by a real temp directory and a Volume
     * that references it by handle, then refresh Craft's volume/fs caches so
     * the legacy services (Craft::$app->volumes / ->fs) the tools call resolve
     * a writable volume. The native Volume::factory() only writes
     * `fs => Local::class` and never registers an actual filesystem, so assets
     * cannot be written to it ("Volume is missing or has an invalid filesystem
     * handle.").
     */
    function seedImageVolume(string $handle, string $name): \CraftCms\Cms\Asset\Models\Volume
    {
        $path = sys_get_temp_dir().'/craftai-vol-'.$handle.'-'.bin2hex(random_bytes(4));
        \craft\helpers\FileHelper::createDirectory($path);

        $fs = new \CraftCms\Cms\Filesystem\Filesystems\Local();
        $fs->name = $name;
        $fs->handle = $handle;
        $fs->path = $path;
        $fs->hasUrls = false;
        \CraftCms\Cms\Support\Facades\Filesystems::saveFilesystem($fs);

        $volume = \CraftCms\Cms\Asset\Models\Volume::factory()->create([
            'name' => $name,
            'handle' => $handle,
            'fs' => $handle,
        ]);

        // Drop the volume service's memoized snapshot so the next handle
        // lookup re-reads from the DB and sees this (and any sibling) volume.
        \CraftCms\Cms\Support\Facades\Volumes::reset();

        return $volume;
    }
}

if (! function_exists('seedAsset')) {
    /**
     * Create a real Asset element in the given volume by running the plugin's
     * UpsertAsset tool against a copy of the test image (the native Asset
     * factory needs a fully wired volume+folder+file).
     */
    function seedAsset(string $volumeHandle): \craft\elements\Asset
    {
        $source = tempnam(sys_get_temp_dir(), 'craftai-dispatch-asset').'.jpg';
        copy(__DIR__.'/stubs/images/gray.jpg', $source);

        $registry = new ToolRegistry();
        $registry->register(UpsertAsset::class);

        $output = $registry->execute('upsert_asset', [
            'volume' => $volumeHandle,
            'filename' => 'dispatch-'.bin2hex(random_bytes(4)).'.jpg',
            'sourcePath' => $source,
        ]);

        expect($output->isError)->toBeFalse($output->text);

        @unlink($source);

        $id = (int) json_decode($output->text, true)['data']['asset']['id'];

        return Asset::find()->id($id)->status(null)->one();
    }
}

/**
 * Inert LLM provider used by the dispatcher tests. We bind this in place of
 * the real provider singleton so the container can construct {@see AgentLoop}
 * (the dispatcher resolves it at fire time) without requiring a configured
 * API key — none of the tests actually drive the agent loop, they just assert
 * on the records the dispatcher writes before the job is queued.
 */
final class DispatcherTestNoopProvider implements LlmProvider
{
    public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
    {
        return new ProviderResponse(id: 'noop', content: [], stopReason: 'end_turn');
    }
}

beforeEach(function () {
    seedSection('posts', 'Posts');
    seedSection('news', 'News');
    seedImageVolume('uploads', 'Uploads');
    seedImageVolume('library', 'Library');

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    $this->loginCraftUser((int) $user->id);

    // The Plugin globally listens on Entry::EVENT_AFTER_SAVE etc., so any
    // factory-driven save would itself dispatch. We keep the test-session
    // context primed (from TestCase::setUp) during fixture construction so
    // those listeners short-circuit on the recursion guard. Each test calls
    // dispatchManually() to deliberately end the context for the one call it
    // wants to measure.
    /** @var ToolContext $ctx */
    $ctx = Craft::$container->get(ToolContext::class);
    if ($ctx->getSessionId() === null) {
        $ctx->begin('test-session', null, ClientType::CP);
    }

    // Override the LLM provider binding so AgentLoop construction succeeds
    // even when the test config has no apiKey. The dispatcher never invokes
    // the loop's send path here — it only writes seed messages — so an inert
    // provider is enough.
    Craft::$container->setSingleton(LlmProvider::class, fn () => new DispatcherTestNoopProvider());
});

/**
 * End the ambient recursion-guard context and run the dispatcher once. Tests
 * use this rather than calling `$dispatcher->dispatch()` directly so factory
 * setup runs under the recursion guard (no spurious sessions) while the
 * single intentional dispatch under test still fires.
 */
function dispatchManually(string $eventKey, \craft\base\ElementInterface $element): void
{
    /** @var ToolContext $ctx */
    $ctx = Craft::$container->get(ToolContext::class);
    $ctx->end();

    try {
        /** @var AutomationDispatcher $dispatcher */
        $dispatcher = Craft::$container->get(AutomationDispatcher::class);
        $dispatcher->dispatch($eventKey, $element);
    } finally {
        // Re-prime the recursion guard so any subsequent factory saves in
        // the same test (e.g. the section/volume filter tests, which call
        // dispatchManually twice with different elements) stay quiet.
        $ctx->begin('test-session', null, ClientType::CP);
    }
}

function applySettings(array $automations): Settings
{
    $settings = new Settings();
    $settings->setAutomations($automations);

    // Inject the settings into the plugin instance so the dispatcher
    // sees them when it reads via Plugin::getInstance()->getSettings().
    // The base Plugin stores its lazily-created model in $_settings on the
    // parent class, so we reflect against the base class to find the
    // property rather than the subclass.
    $plugin = Plugin::getInstance();
    $prop = (new \ReflectionClass(\craft\base\Plugin::class))->getProperty('_settings');
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

    $entry = seedEntry('posts', ['title' => 'Canonical']);
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    dispatchManually(Automation::EVENT_DRAFT_SAVED, $draft);

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

    $entry = seedEntry('posts', []);
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    dispatchManually(Automation::EVENT_DRAFT_SAVED, $draft);

    expect(SessionRecord::find()->all())->toHaveCount(0);
});

it('respects the volume filter for asset-saved events', function () {
    applySettings([
        [
            'event' => Automation::EVENT_ASSET_SAVED,
            'volumeHandle' => 'library',
            'prompt' => 'library only',
            'enabled' => true,
        ],
    ]);

    $uploadAsset = seedAsset('uploads');
    dispatchManually(Automation::EVENT_ASSET_SAVED, $uploadAsset);

    expect(SessionRecord::find()->all())->toHaveCount(0);

    $libraryAsset = seedAsset('library');
    dispatchManually(Automation::EVENT_ASSET_SAVED, $libraryAsset);

    expect(SessionRecord::find()->all())->toHaveCount(1);
});

it('ignores volumeHandle on entry-shaped events', function () {
    applySettings([
        [
            'event' => Automation::EVENT_DRAFT_SAVED,
            // Hand-edited config could leave a stale volumeHandle on a rule
            // whose event was swapped to a section-shaped one. The dispatcher
            // should consult sectionHandle only and ignore the volume.
            'sectionHandle' => '',
            'volumeHandle' => 'library',
            'prompt' => 'fire anyway',
            'enabled' => true,
        ],
    ]);

    $entry = seedEntry('posts', []);
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    dispatchManually(Automation::EVENT_DRAFT_SAVED, $draft);

    expect(SessionRecord::find()->all())->toHaveCount(1);
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

    $postsEntry = seedEntry('posts', []);
    $postsDraft = Craft::$app->drafts->createDraft($postsEntry, 1);

    dispatchManually(Automation::EVENT_DRAFT_SAVED, $postsDraft);

    expect(SessionRecord::find()->all())->toHaveCount(0);

    $newsEntry = seedEntry('news', []);
    $newsDraft = Craft::$app->drafts->createDraft($newsEntry, 1);
    dispatchManually(Automation::EVENT_DRAFT_SAVED, $newsDraft);

    expect(SessionRecord::find()->all())->toHaveCount(1);
});

it('bails when invoked inside an active tool context (recursion guard)', function () {
    applySettings([
        [
            'event' => Automation::EVENT_DRAFT_SAVED,
            'prompt' => 'should be skipped',
            'enabled' => true,
        ],
    ]);

    // Simulate "we're inside an agent loop" by priming the shared context to
    // a session id distinct from TestCase::setUp's test-session. The dispatch
    // call goes directly to the dispatcher because dispatchManually() ends
    // the ambient context — which is exactly what we want to verify isn't
    // happening here.
    /** @var ToolContext $ctx */
    $ctx = Craft::$container->get(ToolContext::class);
    $ctx->begin('agent-session', 'tu-x', ClientType::CP);

    $entry = seedEntry('posts', []);
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    /** @var AutomationDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(AutomationDispatcher::class);
    $dispatcher->dispatch(Automation::EVENT_DRAFT_SAVED, $draft);

    expect(SessionRecord::find()->all())->toHaveCount(0);
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

    $entry = seedEntry('posts', []);
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    dispatchManually(Automation::EVENT_DRAFT_SAVED, $draft);

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

    $entry = seedEntry('posts', []);
    $draft = Craft::$app->drafts->createDraft($entry, 1);

    dispatchManually(Automation::EVENT_DRAFT_SAVED, $draft);

    $titles = array_map(static fn ($s) => $s->title, SessionRecord::find()->all());
    expect($titles)->toHaveCount(2);
    $joined = implode('|', $titles);
    expect($joined)->toContain('Rule A');
    expect($joined)->toContain('Rule B');
});
