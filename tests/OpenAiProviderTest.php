<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\InvalidMessageEncodingException;
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

it('captures reasoning_content from a DeepSeek-style assistant response as a thinking block', function () {
    $cap = new OpenAiCapture(json_encode([
        'id' => 'cc_r1',
        'choices' => [[
            'message' => [
                'role' => 'assistant',
                'content' => 'final answer',
                'reasoning_content' => 'step by step thoughts',
            ],
            'finish_reason' => 'stop',
        ]],
    ]));

    $response = $cap->provider->createMessage(
        messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]],
    );

    expect($response->content[0])->toBe(['type' => 'thinking', 'thinking' => 'step by step thoughts']);
    expect($response->content[1])->toBe(['type' => 'text', 'text' => 'final answer']);
});

it('echoes reasoning_content back on follow-up turns when a thinking block is in history', function () {
    // DeepSeek requires the prior turn's reasoning_content to be passed back.
    $cap = new OpenAiCapture(json_encode([
        'id' => 'cc_r2',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'finish_reason' => 'stop',
        ]],
    ]));

    $cap->provider->createMessage(messages: [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ['role' => 'assistant', 'content' => [
            ['type' => 'thinking', 'thinking' => 'pondering...'],
            ['type' => 'text', 'text' => 'hello'],
        ]],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'again']]],
    ]);

    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    expect($sent['messages'][1]['role'])->toBe('assistant');
    expect($sent['messages'][1]['reasoning_content'])->toBe('pondering...');
    expect($sent['messages'][1]['content'])->toBe('hello');
});

it('skips replayed assistant turns that contain only error blocks', function () {
    // AgentJob persists provider failures as `[{type: error, text: ...}]` on the
    // assistant role. Those rows must not be re-sent to the API on subsequent
    // turns — strict providers (DeepSeek) reject the resulting empty assistant
    // entry, and even tolerant providers shouldn't see our internal markers.
    $cap = new OpenAiCapture(json_encode([
        'id' => 'cc_skip',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'finish_reason' => 'stop',
        ]],
    ]));

    $cap->provider->createMessage(messages: [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'first try']]],
        ['role' => 'assistant', 'content' => [
            ['type' => 'error', 'text' => 'previous failure'],
        ]],
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'second try']]],
    ]);

    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    $roles = array_column($sent['messages'], 'role');
    expect($roles)->toBe(['user', 'user']);
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
    // DeepSeek (and other strict OpenAI-compatible providers) reject assistant
    // messages with `content: null`, so we omit the key entirely when there are
    // tool_calls and no text.
    expect($sent['messages'][1])->not->toHaveKey('content');
    expect($sent['messages'][2])->toBe([
        'role' => 'tool',
        'tool_call_id' => 'tu_1',
        'content' => 'all good',
    ]);
});

it('throws InvalidMessageEncodingException before POSTing a body with invalid UTF-8 in a tool_result', function () {
    // Empty body — we should never reach the HTTP call, so no response needed.
    $cap = new OpenAiCapture('');

    $invoke = fn () => $cap->provider->createMessage(messages: [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'check']]],
        ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'tu_bad', 'name' => 'fetch_webpage', 'input' => []],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'tu_bad', 'content' => "page contents \x80 with bad byte"],
        ]],
    ]);

    expect($invoke)->toThrow(InvalidMessageEncodingException::class);
    expect($cap->history)->toBeEmpty();
});

it('names the offending tool_use_id in the InvalidMessageEncodingException', function () {
    $cap = new OpenAiCapture('');

    try {
        $cap->provider->createMessage(messages: [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'check']]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'tu_named', 'name' => 'fetch_webpage', 'input' => []],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'tu_named', 'content' => "bad \x80 byte"],
            ]],
        ]);
        expect(false)->toBeTrue('expected an exception');
    } catch (InvalidMessageEncodingException $e) {
        expect($e->toolUseId)->toBe('tu_named');
        expect($e->getMessage())->toContain('tu_named');
    }
});

it('flattens an array tool_result to a text-only tool message and drops image content for cross-provider compatibility', function () {
    // Strict OpenAI-compatible providers (DeepSeek via opencode.ai zen, etc.)
    // reject array content on tool messages AND on user follow-ups. So once
    // an image-bearing tool_result lands in history, the safe shape to
    // forward is text-only on the tool message — the front-end still
    // renders the image inline from the persisted Anthropic-shaped block,
    // but the chat model gets a string.
    $cap = new OpenAiCapture(json_encode([
        'id' => 'cc_img_1',
        'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'thanks'],
            'finish_reason' => 'stop',
        ]],
    ]));

    $cap->provider->createMessage(messages: [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'draw me one']]],
        ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'tu_img', 'name' => 'generate_image_nano_banana', 'input' => []],
        ]],
        ['role' => 'user', 'content' => [
            [
                'type' => 'tool_result',
                'tool_use_id' => 'tu_img',
                'content' => [
                    ['type' => 'text', 'text' => '{"images":[{"id":42,"url":"https://example.test/cat.png"}]}'],
                    ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/cat.png']],
                ],
            ],
        ]],
    ]);

    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);

    // The tool message is text-only — the JSON payload from the tool, no
    // image_url part, no array content.
    expect($sent['messages'][2])->toBe([
        'role' => 'tool',
        'tool_call_id' => 'tu_img',
        'content' => '{"images":[{"id":42,"url":"https://example.test/cat.png"}]}',
    ]);

    // Crucially, we do NOT emit a follow-up user message with image_url
    // parts — that's what was breaking DeepSeek's strict deserializer.
    expect($sent['messages'])->toHaveCount(3);
});
