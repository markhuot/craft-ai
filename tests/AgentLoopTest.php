<?php

use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\AnthropicClient;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Test double that returns a scripted sequence of API responses.
 */
class FakeAnthropicClient extends AnthropicClient
{
    /** @var list<array{id: string, role: string, content: list<array<string, mixed>>, stop_reason: string|null}> */
    public array $responses;

    /** @var list<array{messages: list<array{role: string, content: string|list<array<string, mixed>>}>, tools: list<array<string, mixed>>}> */
    public array $calls = [];

    /**
     * @param list<array{id: string, role: string, content: list<array<string, mixed>>, stop_reason: string|null}> $responses
     */
    public function __construct(array $responses)
    {
        // Skip parent constructor — we don't want to make real HTTP calls.
        $this->responses = $responses;
    }

    public function createMessage(array $messages, array $tools = [], string $system = ''): array
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];

        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \RuntimeException('FakeAnthropicClient exhausted scripted responses');
        }

        return $next;
    }
}

beforeEach(function () {
    static $tableEnsured = false;
    if (! $tableEnsured) {
        $tableEnsured = true;
        $schema = Craft::$app->getDb()->getSchema()->getTableSchema('{{%craftai_messages}}', true);
        if ($schema === null) {
            (new markhuot\craftai\migrations\Install())->safeUp();
        }
    }

    $this->registry = new ToolRegistry();
    $this->registry->register(GetHealth::class);
});

it('persists the user message and a single assistant response when no tools are called', function () {
    $client = new FakeAnthropicClient([
        [
            'id' => 'msg_1',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'stop_reason' => 'end_turn',
        ],
    ]);

    (new AgentLoop($client, $this->registry))->run('session-A', 'Hi there');

    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-A'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    expect($records)->toHaveCount(2);
    expect($records[0]->role)->toBe('user');
    expect($records[1]->role)->toBe('assistant');
    expect($client->calls)->toHaveCount(1);
});

it('executes tool_use blocks and persists tool_result before the next turn', function () {
    $client = new FakeAnthropicClient([
        [
            'id' => 'msg_1',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'get_health', 'input' => []],
            ],
            'stop_reason' => 'tool_use',
        ],
        [
            'id' => 'msg_2',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'All systems good.']],
            'stop_reason' => 'end_turn',
        ],
    ]);

    (new AgentLoop($client, $this->registry))->run('session-B', 'How are things?');

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

    expect($client->calls)->toHaveCount(2);
});

it('passes the tool catalog from the registry into every API call', function () {
    $client = new FakeAnthropicClient([
        [
            'id' => 'msg_1',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Done.']],
            'stop_reason' => 'end_turn',
        ],
    ]);

    (new AgentLoop($client, $this->registry))->run('session-C', 'hi');

    expect($client->calls[0]['tools'])->toHaveCount(1);
    expect($client->calls[0]['tools'][0]['name'])->toBe('get_health');
    expect($client->calls[0]['tools'][0])->toHaveKey('input_schema');
});
