<?php

use craft\elements\Asset;
use craft\elements\User;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
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

/**
 * Create a real Asset element in the given volume by running the plugin's
 * UpsertAsset tool against a copy of the test image. The native Asset factory
 * needs a fully wired volume+folder+file, so we lean on the same write path
 * the app uses (and which we've already proven works in seedImageVolume).
 */
function seedAsset(string $volumeHandle): \craft\elements\Asset
{
    $source = tempnam(sys_get_temp_dir(), 'craftai-aistar-asset').'.jpg';
    copy(__DIR__.'/stubs/images/gray.jpg', $source);

    $registry = new ToolRegistry();
    $registry->register(UpsertAsset::class);

    $output = $registry->execute('upsert_asset', [
        'volume' => $volumeHandle,
        'filename' => 'aistar-'.bin2hex(random_bytes(4)).'.jpg',
        'sourcePath' => $source,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    @unlink($source);

    $id = (int) json_decode($output->text, true)['data']['asset']['id'];

    return Asset::find()->id($id)->status(null)->one();
}

beforeEach(function () {
    seedSection('posts', 'Posts');
    seedImageVolume('uploads', 'Uploads');

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    $this->loginCraftUser((int) $user->id);

    // Stub the LLM so the queued AgentJob doesn't try to call out.
    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_test', [['type' => 'text', 'text' => 'ok']], 'end_turn');
        }
    });
});

function postFillField(array $body) {
    return test()->postJson('admin?action=craft-ai/ai-star/fill-field', $body);
}

it('creates a session and queues an agent job when filling a field', function () {
    $entry = seedEntry('posts', ['title' => 'Sample Entry']);

    $response = postFillField([
        'elementId' => $entry->id,
        'isDraft' => 0,
        'fieldHandle' => 'summary',
        'fieldLabel' => 'Summary',
    ]);

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    expect($body['ok'] ?? null)->toBeTrue();
    expect($body['sessionId'] ?? null)->toBeString();

    $sessionId = $body['sessionId'];
    $session = SessionRecord::findOne(['id' => $sessionId]);
    expect($session)->not->toBeNull();
    expect($session->userId)->toBe(1);
    expect($session->toolMode)->toBe('full');
    expect($session->clientType)->toBe('cp');
    expect($session->title)->toContain('Summary');

    // Two seeded messages: the system note pinning element + field,
    // and the user-role directive that kicks off the agent.
    $systemNote = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'system'])
        ->one();
    expect($systemNote)->not->toBeNull();
    expect($systemNote->content)->toContain('ai-fill-field');
    expect($systemNote->content)->toContain('`summary`');
    expect($systemNote->content)->toContain((string) $entry->id);

    $userMessage = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'user'])
        ->one();
    expect($userMessage)->not->toBeNull();
    expect($userMessage->content)->toContain('summary');
});

it('rejects a fill request when the target entry does not exist', function () {
    postFillField([
        'elementId' => 99999999,
        'isDraft' => 0,
        'fieldHandle' => 'summary',
    ])->assertNotFound();
});

it('rejects a fill request when the field handle is missing', function () {
    $this->withoutExceptionHandling();
    $entry = seedEntry('posts', ['title' => 'Sample Entry']);

    $threw = false;
    try {
        postFillField([
            'elementId' => $entry->id,
            'isDraft' => 0,
        ]);
    } catch (\yii\web\BadRequestHttpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('works for assets, routing the agent to upsert_asset instead of upsert_draft', function () {
    $asset = seedAsset('uploads');

    $response = postFillField([
        'elementId' => $asset->id,
        'isDraft' => 0,
        'fieldHandle' => 'alt',
        'fieldLabel' => 'Alt text',
    ]);

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    $sessionId = $body['sessionId'];

    $systemNote = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'system'])
        ->one();
    expect($systemNote)->not->toBeNull();
    // Asset-specific verbiage: the agent should be steered at `get_asset`
    // / `upsert_asset`, not the draft tool family. Without this the user
    // who clicks the star on an asset edit screen would get the same
    // entry-flavored prompt and either hallucinate a draft id or just
    // fail to save anything.
    expect($systemNote->content)->toContain('asset');
    expect($systemNote->content)->toContain('get_asset');
    expect($systemNote->content)->toContain('upsert_asset');
    expect($systemNote->content)->not->toContain('get_draft');

    $userMessage = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'user'])
        ->one();
    expect($userMessage->content)->toContain('asset');
});

it('threads the editor\'s current site into the system note so the agent picks the right locale', function () {
    $entry = seedEntry('posts', ['title' => 'Sample Entry']);
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

    $response = postFillField([
        'elementId' => $entry->id,
        'isDraft' => 0,
        'fieldHandle' => 'summary',
        'fieldLabel' => 'Summary',
        'siteId' => $primarySiteId,
    ]);

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    $sessionId = $body['sessionId'];

    $systemNote = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'system'])
        ->one();
    expect($systemNote)->not->toBeNull();
    // The note has to name both the site handle and the language code so
    // the agent has the locale anchor before its first tool call — and
    // it has to spell out that `site: "<handle>"` belongs on the get/
    // upsert calls. Without that, the agent reads the entry off the
    // install's primary site and only discovers the actual locale by
    // trial-and-error.
    $primarySite = Craft::$app->getSites()->getPrimarySite();
    expect($systemNote->content)->toContain("Site: `{$primarySite->handle}`");
    expect($systemNote->content)->toContain("language `{$primarySite->language}`");
    expect($systemNote->content)->toContain("site: \\\"{$primarySite->handle}\\\"");
});

it('silently ignores an unknown siteId rather than 500-ing or leaking it into the prompt', function () {
    $entry = seedEntry('posts', ['title' => 'Sample Entry']);

    $response = postFillField([
        'elementId' => $entry->id,
        'isDraft' => 0,
        'fieldHandle' => 'summary',
        'siteId' => 99999999,
    ]);

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    $sessionId = $body['sessionId'];

    $systemNote = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'system'])
        ->one();
    // Bogus id falls through to "no site stanza" — same shape a single-
    // site install (or a CP screen without a siteId input) would
    // produce. The fill still proceeds against the primary site.
    expect($systemNote->content)->not->toContain('Site: `');
});

it('threads matrix-block context into the system note when provided', function () {
    $entry = seedEntry('posts', ['title' => 'Sample Entry']);

    $response = postFillField([
        'elementId' => $entry->id,
        'isDraft' => 0,
        'fieldHandle' => 'innerBody',
        'fieldLabel' => 'Inner Body',
        'blockElementId' => 12345,
        'blockTypeHandle' => 'callout',
    ]);

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    $sessionId = $body['sessionId'];

    $systemNote = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'system'])
        ->one();
    expect($systemNote->content)->toContain('Matrix block: nested entry #12345');
    expect($systemNote->content)->toContain('callout');

    $userMessage = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'user'])
        ->one();
    expect($userMessage->content)->toContain('matrix block');
});
