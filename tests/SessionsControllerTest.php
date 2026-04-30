<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;

function loginTestUser(): void {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    Craft::$app->getUser()->setIdentity($user);
}

beforeEach(function () {
    loginTestUser();

    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_test', [['type' => 'text', 'text' => 'ok']], 'end_turn');
        }
    });
});

it('renders the sessions index with grouped session rows', function () {
    $a = new MessageRecord();
    $a->sessionId = 'aaaa-1';
    $a->role = 'user';
    $a->content = json_encode([['type' => 'text', 'text' => 'hi']]);
    $a->save();

    $b = new MessageRecord();
    $b->sessionId = 'aaaa-1';
    $b->role = 'assistant';
    $b->content = json_encode([['type' => 'text', 'text' => 'hello']]);
    $b->save();

    $c = new MessageRecord();
    $c->sessionId = 'bbbb-2';
    $c->role = 'user';
    $c->content = json_encode([['type' => 'text', 'text' => 'yo']]);
    $c->save();

    $response = $this->get('admin/ai/sessions');

    $response->assertOk();
    $response->assertSee('aaaa-1');
    $response->assertSee('bbbb-2');
});

it('mints a new session id and redirects to its CP page', function () {
    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody(['action' => 'craft-ai/sessions/new'])
        ->send();

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toMatch('#/ai/session/[A-Za-z0-9\-]{36}$#');
});

it('renders the chat view with prior messages for the requested session', function () {
    $record = new MessageRecord();
    $record->sessionId = 'session-view-1';
    $record->role = 'user';
    $record->content = json_encode([['type' => 'text', 'text' => 'hello world']]);
    $record->save();

    $response = $this->get('admin/ai/session/session-view-1');

    $response->assertOk();
    $response->assertSee('session-view-1');
    $response->assertSee('hello world');
});

function postSend(array $body) {
    return test()->http('post', 'admin')
        ->withCsrfToken()
        ->addHeader('Accept', 'application/json')
        ->setBody(['action' => 'craft-ai/sessions/send', ...$body])
        ->send();
}

it('queues an AgentJob when the composer sends a message', function () {
    $response = postSend(['sessionId' => 'session-send-1', 'message' => 'do the thing']);

    $response->assertOk();
    $response->assertJsonPath('queued', true);
});

it('does not queue a job for an empty message', function () {
    $response = postSend(['sessionId' => 'session-send-2', 'message' => '   ']);

    $response->assertOk();
    $response->assertJsonPath('queued', false);
});
