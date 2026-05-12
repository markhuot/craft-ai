<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;

beforeEach(function () {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    Craft::$app->getUser()->setIdentity($user);

    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_test', [['type' => 'text', 'text' => 'ok']], 'end_turn');
        }
    });
});

function postMessages(array $body) {
    return test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody(['action' => 'craft-ai/messages/create', ...$body])
        ->send();
}

it('returns messages for a session as JSON, ordered by id', function () {
    foreach (['hi', 'hello', 'goodbye'] as $i => $text) {
        $r = new MessageRecord();
        $r->sessionId = 'mc-1';
        $r->role = $i % 2 === 0 ? 'user' : 'assistant';
        $r->content = json_encode([['type' => 'text', 'text' => $text]]);
        $r->save();
    }

    $response = test()->get('admin?action=craft-ai/messages&sessionId=mc-1');

    $response->assertOk();
    $response->assertJsonCount(3, 'messages');
    $response->assertJsonPath('messages.0.content.0.text', 'hi');
    $response->assertJsonPath('messages.2.content.0.text', 'goodbye');
    $response->assertJsonPath('previewRequest', null);
});

it('filters by the after parameter', function () {
    $ids = [];
    foreach (['a', 'b', 'c'] as $text) {
        $r = new MessageRecord();
        $r->sessionId = 'mc-2';
        $r->role = 'user';
        $r->content = json_encode([['type' => 'text', 'text' => $text]]);
        $r->save();
        $ids[] = $r->id;
    }

    $response = test()->get('admin?action=craft-ai/messages&sessionId=mc-2&after='.$ids[0]);

    $response->assertOk();
    $response->assertJsonCount(2, 'messages');
});

it('queues an AgentJob via async create', function () {
    $response = postMessages([
        'sessionId' => 'mc-3',
        'message' => 'queue please',
        'async' => '1',
    ]);

    $response->assertOk();
    $response->assertJsonPath('queued', true);
});

it('runs the agent loop inline when async is not set', function () {
    $response = postMessages([
        'sessionId' => 'mc-4',
        'message' => 'run inline',
    ]);

    $response->assertOk();
    $response->assertJsonPath('ok', true);

    $count = MessageRecord::find()->where(['sessionId' => 'mc-4'])->count();
    expect((int) $count)->toBe(2);
});

it('includes resolved attachments on user messages in the messages JSON', function () {
    \markhuot\craftpest\factories\Volume::factory()->name('Uploads')->handle('uploads')->create();
    $sourceFile = tempnam(sys_get_temp_dir(), 'craftai-asset-msg').'.jpg';
    copy(__DIR__.'/../vendor/markhuot/craft-pest-core/stubs/images/gray.jpg', $sourceFile);

    $registry = new \markhuot\craftai\tools\ToolRegistry();
    $registry->register(\markhuot\craftai\tools\UpsertAsset::class);

    $assetCreated = $registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'message-attachment.jpg',
        'sourcePath' => $sourceFile,
    ]);
    @unlink($sourceFile);

    expect($assetCreated->isError)->toBeFalse($assetCreated->text);
    $assetId = json_decode($assetCreated->text, true)['data']['id'];

    $record = new MessageRecord();
    $record->sessionId = 'mc-attachments';
    $record->role = 'user';
    $record->content = json_encode([['type' => 'text', 'text' => 'see this']]);
    $record->assetIds = json_encode([$assetId]);
    $record->save();

    $response = test()->get('admin?action=craft-ai/messages&sessionId=mc-attachments');

    $response->assertOk();
    $response->assertJsonCount(1, 'messages');
    $response->assertJsonPath('messages.0.attachments.0.id', $assetId);
    $response->assertJsonPath('messages.0.attachments.0.filename', 'message-attachment.jpg');
});

it('returns an empty attachments array when the user message has no assetIds', function () {
    $record = new MessageRecord();
    $record->sessionId = 'mc-no-attachments';
    $record->role = 'user';
    $record->content = json_encode([['type' => 'text', 'text' => 'hi']]);
    $record->save();

    $response = test()->get('admin?action=craft-ai/messages&sessionId=mc-no-attachments');

    $response->assertOk();
    $response->assertJsonPath('messages.0.attachments', []);
});

it('surfaces the next pending preview request alongside the messages', function () {
    $r = new MessageRecord();
    $r->sessionId = 'mc-preview';
    $r->role = 'user';
    $r->content = json_encode([['type' => 'text', 'text' => 'show me']]);
    $r->save();

    $service = new \markhuot\craftai\preview\PreviewService();
    $service->create('mc-preview', 'tu-1', 'open', ['url' => 'https://example.com']);

    $response = test()->get('admin?action=craft-ai/messages&sessionId=mc-preview');

    $response->assertOk();
    $response->assertJsonPath('previewRequest.type', 'open');
    $response->assertJsonPath('previewRequest.status', 'pending');
    $response->assertJsonPath('previewRequest.input.url', 'https://example.com');
});

it('omits the previewRequest when nothing is pending for the session', function () {
    $r = new MessageRecord();
    $r->sessionId = 'mc-no-preview';
    $r->role = 'user';
    $r->content = json_encode([['type' => 'text', 'text' => 'hi']]);
    $r->save();

    $response = test()->get('admin?action=craft-ai/messages&sessionId=mc-no-preview');

    $response->assertOk();
    $response->assertJsonPath('previewRequest', null);
    $response->assertJsonPath('lastPreviewUrl', null);
});

it('exposes the last successfully-opened preview URL on the messages envelope', function () {
    $r = new MessageRecord();
    $r->sessionId = 'mc-last-url';
    $r->role = 'user';
    $r->content = json_encode([['type' => 'text', 'text' => 'hi']]);
    $r->save();

    $service = new \markhuot\craftai\preview\PreviewService();
    $id = $service->create('mc-last-url', null, 'open', ['url' => 'https://example.com/x']);
    $service->complete($id, ['loadedAt' => 1, 'finalUrl' => 'https://example.com/x/final']);

    $response = test()->get('admin?action=craft-ai/messages&sessionId=mc-last-url');

    $response->assertOk();
    $response->assertJsonPath('lastPreviewUrl', 'https://example.com/x/final');
});
