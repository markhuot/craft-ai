<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\OpenAiProvider;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolDescriptor;

class OpenAiCapture
{
    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    public OpenAiProvider $provider;

    public function __construct(string $body)
    {
        $mock = new MockHandler([new Response(200, [], $body)]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $this->provider = new OpenAiProvider('test-key', 'gpt-4o', $client);
    }
}

it('translates an Anthropic-shaped message history into OpenAI Chat format', function () {
    $cap = new OpenAiCapture(json_encode([
        'id' => 'cc_1',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'hi back'],
            'finish_reason' => 'stop',
        ]],
    ]));

    $response = $cap->provider->createMessage(
        messages: [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ],
        tools: [new ToolDescriptor(GetHealth::class)],
        system: 'You are helpful.',
    );

    /** @var array<string, mixed> $sent */
    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    expect($sent['messages'][0]['role'])->toBe('system');
    expect($sent['messages'][1])->toBe(['role' => 'user', 'content' => 'hi']);
    expect($sent['tools'][0]['type'])->toBe('function');
    expect($sent['tools'][0]['function']['name'])->toBe('get_health');

    expect($response->stopReason)->toBe('end_turn');
    expect($response->content[0])->toBe(['type' => 'text', 'text' => 'hi back']);
});

it('translates an OpenAI tool_calls response into Anthropic-shaped tool_use blocks', function () {
    $cap = new OpenAiCapture(json_encode([
        'id' => 'cc_2',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_abc',
                    'type' => 'function',
                    'function' => ['name' => 'get_health', 'arguments' => '{"foo":"bar"}'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
    ]));

    $response = $cap->provider->createMessage(
        messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'check']]]],
        tools: [],
    );

    expect($response->stopReason)->toBe('tool_use');
    expect($response->content[0])->toMatchArray([
        'type' => 'tool_use',
        'id' => 'call_abc',
        'name' => 'get_health',
        'input' => ['foo' => 'bar'],
    ]);
});

it('emits OpenAI tool_calls and role=tool messages when given an Anthropic tool_use/tool_result history', function () {
    $cap = new OpenAiCapture(json_encode([
        'id' => 'cc_3',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'done'],
            'finish_reason' => 'stop',
        ]],
    ]));

    $cap->provider->createMessage(messages: [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'check']]],
        ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_health', 'input' => []],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'all good'],
        ]],
    ]);

    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    expect($sent['messages'][0])->toBe(['role' => 'user', 'content' => 'check']);
    expect($sent['messages'][1]['role'])->toBe('assistant');
    expect($sent['messages'][1]['tool_calls'][0]['function']['name'])->toBe('get_health');
    expect($sent['messages'][2])->toBe([
        'role' => 'tool',
        'tool_call_id' => 'tu_1',
        'content' => 'all good',
    ]);
});
