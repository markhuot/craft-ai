<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\AnthropicProvider;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolDescriptor;

class AnthropicCapture
{
    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    public AnthropicProvider $provider;

    public function __construct(string $body)
    {
        $mock = new MockHandler([new Response(200, [], $body)]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $this->provider = new AnthropicProvider('test-key', 'claude-test', $client);
    }
}

it('forwards the message history, model, and tool catalog to Anthropic', function () {
    $cap = new AnthropicCapture(json_encode([
        'id' => 'msg_1',
        'content' => [['type' => 'text', 'text' => 'hi']],
        'stop_reason' => 'end_turn',
    ]));

    $cap->provider->createMessage(
        messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]],
        tools: [new ToolDescriptor(GetHealth::class)],
        system: 'You are concise.',
    );

    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    expect($sent['model'])->toBe('claude-test');
    expect($sent['system'])->toBe('You are concise.');
    expect($sent['messages'][0]['role'])->toBe('user');
    expect($sent['tools'][0]['name'])->toBe('get_health');
    expect($sent['tools'][0])->toHaveKey('input_schema');
});

it('omits system and tools when not provided', function () {
    $cap = new AnthropicCapture(json_encode([
        'id' => 'msg_2',
        'content' => [['type' => 'text', 'text' => 'ok']],
        'stop_reason' => 'end_turn',
    ]));

    $cap->provider->createMessage(
        messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]]],
    );

    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    expect($sent)->not->toHaveKey('system');
    expect($sent)->not->toHaveKey('tools');
});

it('parses the response into a ProviderResponse', function () {
    $cap = new AnthropicCapture(json_encode([
        'id' => 'msg_3',
        'content' => [
            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_health', 'input' => []],
        ],
        'stop_reason' => 'tool_use',
    ]));

    $response = $cap->provider->createMessage(
        messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'check']]]],
    );

    expect($response->id)->toBe('msg_3');
    expect($response->stopReason)->toBe('tool_use');
    expect($response->content[0]['type'])->toBe('tool_use');
});

it('defaults stopReason to end_turn when the response omits it', function () {
    $cap = new AnthropicCapture(json_encode([
        'id' => 'msg_4',
        'content' => [['type' => 'text', 'text' => 'done']],
        'stop_reason' => null,
    ]));

    $response = $cap->provider->createMessage(messages: [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'x']]],
    ]);

    expect($response->stopReason)->toBe('end_turn');
});
