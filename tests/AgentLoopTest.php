<?php

use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
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

it('breaks immediately and writes a stop marker when stopRequested is set before the next turn', function () {
    $session = new SessionRecord();
    $session->id = 'session-stop-pre';
    $session->active = true;
    $session->stopRequested = true;
    $session->save();

    // Provider has zero scripted responses; if the loop calls it, the
    // FakeProvider would throw, proving the early-exit happened.
    $provider = new FakeProvider([]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-stop-pre', 'go');
    $loop->run('session-stop-pre');

    expect($provider->calls)->toHaveCount(0);

    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-stop-pre'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    // user message + the synthetic "Stopped by user." assistant marker
    expect($records)->toHaveCount(2);
    expect($records[1]->role)->toBe('assistant');
    $marker = json_decode($records[1]->content, true);
    expect($marker[0]['type'])->toBe('text');
    expect($marker[0]['text'])->toBe('Stopped by user.');
});

it('persists assetIds on the user message when supplied', function () {
    $provider = new FakeProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'Sure']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-assets', 'Look at this', [123, 456]);
    $loop->run('session-assets');

    /** @var MessageRecord $userRecord */
    $userRecord = MessageRecord::find()
        ->where(['sessionId' => 'session-assets', 'role' => 'user'])
        ->orderBy(['id' => SORT_ASC])
        ->one();

    expect($userRecord->assetIds)->not->toBeNull();
    expect(json_decode($userRecord->assetIds, true))->toBe([123, 456]);
});

it('annotates the user message with attached asset details when sending to the provider', function () {
    \markhuot\craftpest\factories\Volume::factory()->name('Uploads')->handle('uploads')->create();
    $sourceFile = tempnam(sys_get_temp_dir(), 'craftai-asset-test').'.jpg';
    copy(__DIR__.'/../vendor/markhuot/craft-pest-core/stubs/images/gray.jpg', $sourceFile);

    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);
    $registry->register(\markhuot\craftai\tools\UpsertAsset::class);

    /** @var \markhuot\craftai\tools\ToolOutput $assetCreated */
    $assetCreated = $registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'attachment.jpg',
        'sourcePath' => $sourceFile,
    ]);
    expect($assetCreated->isError)->toBeFalse($assetCreated->text);
    $assetId = json_decode($assetCreated->text, true)['id'];

    $provider = new FakeProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'thanks']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-annot', 'whats this', [$assetId]);
    $loop->run('session-annot');

    @unlink($sourceFile);

    expect($provider->calls)->toHaveCount(1);
    $messages = $provider->calls[0]['messages'];
    $userMessage = $messages[0];
    expect($userMessage['role'])->toBe('user');

    $textBlocks = array_values(array_filter(
        $userMessage['content'],
        static fn ($b) => ($b['type'] ?? '') === 'text',
    ));

    // The agent should see both the original prompt and a follow-up annotation
    // listing the attached asset.
    expect($textBlocks)->toHaveCount(2);
    expect($textBlocks[0]['text'])->toBe('whats this');
    expect($textBlocks[1]['text'])->toContain("asset id {$assetId}");
    expect($textBlocks[1]['text'])->toContain('attachment.jpg');
    expect($textBlocks[1]['text'])->toContain('get_asset');
});

it('folds a system page-context row into the next user message when sending to the provider', function () {
    $provider = new FakeProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendSystemContext('session-ctx', "<page-context>\nURL: /about\n</page-context>");
    $loop->appendUserMessage('session-ctx', 'tell me more');
    $loop->run('session-ctx');

    expect($provider->calls)->toHaveCount(1);

    $messages = $provider->calls[0]['messages'];
    // The system row must NOT be sent as a separate turn — it's folded into
    // the user message that follows it.
    $roles = array_map(static fn ($m): string => $m['role'], $messages);
    expect($roles)->not->toContain('system');

    $userMessage = $messages[0];
    expect($userMessage['role'])->toBe('user');
    $texts = array_values(array_filter(
        $userMessage['content'],
        static fn ($b) => ($b['type'] ?? '') === 'text',
    ));
    expect($texts)->toHaveCount(2);
    expect($texts[0]['text'])->toContain('<page-context>');
    expect($texts[1]['text'])->toBe('tell me more');
});

it('preserves the system row in the persisted transcript even after folding for the LLM', function () {
    $provider = new FakeProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendSystemContext('session-ctx-keep', 'page note');
    $loop->appendUserMessage('session-ctx-keep', 'hi');
    $loop->run('session-ctx-keep');

    /** @var list<MessageRecord> $records */
    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-ctx-keep'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    // system, user, assistant — all retained in the DB. Folding only affects
    // the live message array we pass to the provider, not what's stored.
    expect($records)->toHaveCount(3);
    expect($records[0]->role)->toBe('system');
    expect($records[1]->role)->toBe('user');
    expect($records[2]->role)->toBe('assistant');
});

it('synthesizes a tool_result when an orphan tool_use was persisted without one', function () {
    // Replays the failure mode that bricked sessions before this heal: the
    // queue worker died after writing an assistant tool_use but before the
    // matching tool_result row got persisted. Without healing, the next LLM
    // call rejects the conversation as malformed.
    $sessionId = 'session-orphan';

    $u = new MessageRecord();
    $u->sessionId = $sessionId;
    $u->role = 'user';
    $u->content = json_encode([['type' => 'text', 'text' => 'show me the preview']]);
    $u->save();

    $a = new MessageRecord();
    $a->sessionId = $sessionId;
    $a->role = 'assistant';
    $a->content = json_encode([
        ['type' => 'text', 'text' => 'opening it'],
        ['type' => 'tool_use', 'id' => 'tu_orphan', 'name' => 'get_preview', 'input' => []],
    ]);
    $a->save();

    // No user/tool_result row follows — the worker died mid-execute.
    $u2 = new MessageRecord();
    $u2->sessionId = $sessionId;
    $u2->role = 'user';
    $u2->content = json_encode([['type' => 'text', 'text' => 'still there?']]);
    $u2->save();

    $provider = new FakeProvider([
        new ProviderResponse('msg_recover', [['type' => 'text', 'text' => 'recovering']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    expect($provider->calls)->toHaveCount(1);
    $messages = $provider->calls[0]['messages'];

    // Sequence sent to the provider: user "show me the preview", assistant
    // (text + orphan tool_use), then a healed user turn that prepends the
    // synthetic tool_result onto the user's "still there?" text — preserving
    // the LLM's tool_use → tool_result invariant.
    expect($messages[1]['role'])->toBe('assistant');
    $orphanTurn = $messages[2];
    expect($orphanTurn['role'])->toBe('user');
    $blocks = $orphanTurn['content'];
    expect($blocks[0]['type'])->toBe('tool_result');
    expect($blocks[0]['tool_use_id'])->toBe('tu_orphan');
    expect($blocks[0]['is_error'])->toBeTrue();
    expect($blocks[0]['content'])->toContain('worker likely failed');
    // The user's actual second message survives, just folded after the heal.
    $textBlocks = array_values(array_filter(
        $blocks,
        static fn ($b): bool => ($b['type'] ?? '') === 'text',
    ));
    expect($textBlocks)->toHaveCount(1);
    expect($textBlocks[0]['text'])->toBe('still there?');
});

it('inserts a synthesized user turn when the orphan tool_use is the very last record', function () {
    // Same failure mode but the worker died before *any* follow-up user
    // message was sent. The next agent run still needs a tool_result before
    // the provider call can succeed.
    $sessionId = 'session-orphan-trailing';

    $u = new MessageRecord();
    $u->sessionId = $sessionId;
    $u->role = 'user';
    $u->content = json_encode([['type' => 'text', 'text' => 'go']]);
    $u->save();

    $a = new MessageRecord();
    $a->sessionId = $sessionId;
    $a->role = 'assistant';
    $a->content = json_encode([
        ['type' => 'tool_use', 'id' => 'tu_trail', 'name' => 'get_preview', 'input' => []],
    ]);
    $a->save();

    $provider = new FakeProvider([
        new ProviderResponse('msg_recover', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    $messages = $provider->calls[0]['messages'];
    // user (go), assistant (tool_use), user (synthesized tool_result)
    expect($messages)->toHaveCount(3);
    expect($messages[2]['role'])->toBe('user');
    expect($messages[2]['content'][0]['type'])->toBe('tool_result');
    expect($messages[2]['content'][0]['tool_use_id'])->toBe('tu_trail');
});

it('leaves intact assistant tool_use turns alone when the matching tool_result is already present', function () {
    $sessionId = 'session-no-orphan';

    $u = new MessageRecord();
    $u->sessionId = $sessionId;
    $u->role = 'user';
    $u->content = json_encode([['type' => 'text', 'text' => 'go']]);
    $u->save();

    $a = new MessageRecord();
    $a->sessionId = $sessionId;
    $a->role = 'assistant';
    $a->content = json_encode([
        ['type' => 'tool_use', 'id' => 'tu_ok', 'name' => 'get_health', 'input' => []],
    ]);
    $a->save();

    $tr = new MessageRecord();
    $tr->sessionId = $sessionId;
    $tr->role = 'user';
    $tr->content = json_encode([
        ['type' => 'tool_result', 'tool_use_id' => 'tu_ok', 'content' => 'ok', 'is_error' => false],
    ]);
    $tr->save();

    $provider = new FakeProvider([
        new ProviderResponse('msg_done', [['type' => 'text', 'text' => 'great']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    $messages = $provider->calls[0]['messages'];
    // No synthetic tool_result added; the existing one survives unchanged.
    $toolResults = [];
    foreach ($messages as $m) {
        foreach ($m['content'] as $block) {
            if (($block['type'] ?? '') === 'tool_result') {
                $toolResults[] = $block;
            }
        }
    }
    expect($toolResults)->toHaveCount(1);
    expect($toolResults[0]['content'])->toBe('ok');
    expect($toolResults[0]['is_error'])->toBeFalse();
});

it('fabricates stopped tool_results and exits when stop is requested mid tool_use turn', function () {
    $session = new SessionRecord();
    $session->id = 'session-stop-mid';
    $session->active = true;
    $session->stopRequested = false;
    $session->save();

    // The provider returns a tool_use response, then on its way out the loop
    // sees stopRequested=true and must NOT execute the tool, but must still
    // emit a tool_result block to keep the conversation log valid.
    $provider = new class([
        new ProviderResponse(
            'msg_tu',
            [['type' => 'tool_use', 'id' => 'tu_42', 'name' => 'get_health', 'input' => []]],
            'tool_use',
        ),
    ]) extends FakeProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            // Simulate the user clicking Stop while the LLM call is in flight.
            $session = SessionRecord::findOne(['id' => 'session-stop-mid']);
            $session->stopRequested = true;
            $session->save();

            return parent::createMessage($messages, $tools, $system);
        }
    };

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-stop-mid', 'check health');
    $loop->run('session-stop-mid');

    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-stop-mid'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    // user prompt, assistant tool_use, fabricated user tool_result, stop marker
    expect($records)->toHaveCount(4);
    expect($records[1]->role)->toBe('assistant');
    expect($records[2]->role)->toBe('user');

    $toolResultContent = json_decode($records[2]->content, true);
    expect($toolResultContent[0]['type'])->toBe('tool_result');
    expect($toolResultContent[0]['tool_use_id'])->toBe('tu_42');
    expect($toolResultContent[0]['content'])->toBe('Stopped by user.');
    expect($toolResultContent[0]['is_error'])->toBeTrue();

    $marker = json_decode($records[3]->content, true);
    expect($marker[0]['text'])->toBe('Stopped by user.');

    // Provider should have been called exactly once — we must not loop again.
    expect($provider->calls)->toHaveCount(1);
});
