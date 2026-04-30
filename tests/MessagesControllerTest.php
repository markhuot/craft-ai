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
    $response->assertJsonCount(3);
    $response->assertJsonPath('0.content.0.text', 'hi');
    $response->assertJsonPath('2.content.0.text', 'goodbye');
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
    $response->assertJsonCount(2);
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
