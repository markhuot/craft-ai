<?php

use craft\elements\User;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftpest\factories\Asset;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();
    Volume::factory()->name('Uploads')->handle('uploads')->create();

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    Craft::$app->getUser()->setIdentity($user);

    // Stub the LLM so the queued AgentJob doesn't try to call out.
    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_test', [['type' => 'text', 'text' => 'ok']], 'end_turn');
        }
    });
});

it('creates a session and queues an agent job when filling a field', function () {
    $entry = Entry::factory()->section('posts')->title('Sample Entry')->create();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/ai-star/fill-field',
            'elementId' => $entry->id,
            'isDraft' => 0,
            'fieldHandle' => 'summary',
            'fieldLabel' => 'Summary',
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
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
    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/ai-star/fill-field',
            'elementId' => 99999999,
            'isDraft' => 0,
            'fieldHandle' => 'summary',
        ])
        ->send();
})->throws(\yii\web\NotFoundHttpException::class);

it('rejects a fill request when the field handle is missing', function () {
    $entry = Entry::factory()->section('posts')->title('Sample Entry')->create();

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/ai-star/fill-field',
            'elementId' => $entry->id,
            'isDraft' => 0,
        ])
        ->send();
})->throws(\yii\web\BadRequestHttpException::class);

it('works for assets, routing the agent to upsert_asset instead of upsert_draft', function () {
    $asset = Asset::factory()->volume('uploads')->create();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/ai-star/fill-field',
            'elementId' => $asset->id,
            'isDraft' => 0,
            'fieldHandle' => 'alt',
            'fieldLabel' => 'Alt text',
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
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
    $entry = Entry::factory()->section('posts')->title('Sample Entry')->create();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/ai-star/fill-field',
            'elementId' => $entry->id,
            'isDraft' => 0,
            'fieldHandle' => 'summary',
            'fieldLabel' => 'Summary',
            'siteId' => $primarySiteId,
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
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
    $entry = Entry::factory()->section('posts')->title('Sample Entry')->create();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/ai-star/fill-field',
            'elementId' => $entry->id,
            'isDraft' => 0,
            'fieldHandle' => 'summary',
            'siteId' => 99999999,
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
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
    $entry = Entry::factory()->section('posts')->title('Sample Entry')->create();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/ai-star/fill-field',
            'elementId' => $entry->id,
            'isDraft' => 0,
            'fieldHandle' => 'innerBody',
            'fieldLabel' => 'Inner Body',
            'blockElementId' => 12345,
            'blockTypeHandle' => 'callout',
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
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
