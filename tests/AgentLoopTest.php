<?php

use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolDescriptor;
use markhuot\craftai\tools\ToolRegistry;

class FakeProvider implements LlmProvider
{
    /** @var list<ProviderResponse> */
    public array $responses;

    /** @var list<array{messages: list<array<string, mixed>>, tools: list<ToolDescriptor>}> */
    public array $calls = [];

    /**
     * @param list<ProviderResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];

        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \RuntimeException('FakeProvider exhausted scripted responses');
        }

        return $next;
    }
}

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetHealth::class);
});

it('persists the user message and a single assistant response when no tools are called', function () {
    $provider = new FakeProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'Hello!']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-A', 'Hi there');
    $loop->run('session-A');

    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-A'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    expect($records)->toHaveCount(2);
    expect($records[0]->role)->toBe('user');
    expect($records[1]->role)->toBe('assistant');
    expect($provider->calls)->toHaveCount(1);
});

it('executes tool_use blocks and persists tool_result before the next turn', function () {
    $provider = new FakeProvider([
        new ProviderResponse(
            'msg_1',
            [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_health', 'input' => []]],
            'tool_use',
        ),
        new ProviderResponse('msg_2', [['type' => 'text', 'text' => 'All systems good.']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-B', 'How are things?');
    $loop->run('session-B');

    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-B'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    // user prompt, assistant tool_use, user tool_result, assistant final text
    expect($records)->toHaveCount(4);
    expect($records[1]->role)->toBe('assistant');
    expect($records[2]->role)->toBe('user');

    /** @var list<array<string, mixed>> $toolResultContent */
    $toolResultContent = json_decode($records[2]->content, true);
    expect($toolResultContent[0]['type'])->toBe('tool_result');
    expect($toolResultContent[0]['tool_use_id'])->toBe('tu_1');
    expect($toolResultContent[0]['content'])->toContain('operational');

    expect($provider->calls)->toHaveCount(2);
});

it('persists the full provider payload on assistant messages but not user messages', function () {
    $rawPayload = [
        'id' => 'msg_raw',
        'choices' => [['message' => ['role' => 'assistant', 'reasoning_content' => 'pondered']]],
        'usage' => ['total_tokens' => 42],
    ];
    $provider = new FakeProvider([
        new ProviderResponse(
            'msg_raw',
            [['type' => 'text', 'text' => 'hi']],
            'end_turn',
            $rawPayload,
        ),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-raw', 'hello');
    $loop->run('session-raw');

    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-raw'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    expect($records[0]->role)->toBe('user');
    expect($records[0]->rawResponse)->toBeNull();

    expect($records[1]->role)->toBe('assistant');
    /** @var array<string, mixed> $stored */
    $stored = json_decode($records[1]->rawResponse, true);
    expect($stored)->toBe($rawPayload);
});

it('passes the tool descriptor catalog from the registry into every provider call', function () {
    $provider = new FakeProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'Done.']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-C', 'hi');
    $loop->run('session-C');

    expect($provider->calls[0]['tools'])->toHaveCount(1);
    expect($provider->calls[0]['tools'][0])->toBeInstanceOf(ToolDescriptor::class);
    expect($provider->calls[0]['tools'][0]->name)->toBe('get_health');
});
