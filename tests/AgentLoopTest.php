<?php

use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\Tool;
use markhuot\craftai\tools\ToolDescriptor;
use markhuot\craftai\tools\ToolKind;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Reproduces the failure mode where an external tool (e.g. fetch_webpage)
 * returns bytes that aren't valid UTF-8 — historically this killed the
 * saveMessage()'s json_encode(JSON_THROW_ON_ERROR) and left the conversation
 * stuck with an unanswered tool_use.
 */
class BadUtf8Tool extends Tool
{
    public const KIND = ToolKind::Read;

    public function __invoke(): ToolOutput
    {
        return new ToolOutput("hello \x80 world \xC3\x28 end");
    }
}

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

it('hides write-only tools from the LLM when the session is in readonly mode', function () {
    $registry = new ToolRegistry();
    $registry->register(\markhuot\craftai\tools\GetHealth::class);
    $registry->register(\markhuot\craftai\tools\UpsertEntry::class);

    $session = new SessionRecord();
    $session->id = 'session-readonly';
    $session->userId = 1;
    $session->toolMode = 'readonly';
    $session->save();

    $provider = new FakeProvider([
        new ProviderResponse('msg_ro', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $registry);
    $loop->appendUserMessage('session-readonly', 'hi');
    $loop->run('session-readonly');

    $names = array_map(static fn (ToolDescriptor $d): string => $d->name, $provider->calls[0]['tools']);
    expect($names)->toBe(['get_health']);
});

it('honors a custom enabledTools allowlist when the session is in custom mode', function () {
    $registry = new ToolRegistry();
    $registry->register(\markhuot\craftai\tools\GetHealth::class);
    $registry->register(\markhuot\craftai\tools\UpsertEntry::class);

    $session = new SessionRecord();
    $session->id = 'session-custom';
    $session->userId = 1;
    $session->toolMode = 'custom';
    $session->enabledTools = json_encode(['upsert_entry']);
    $session->save();

    $provider = new FakeProvider([
        new ProviderResponse('msg_custom', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $registry);
    $loop->appendUserMessage('session-custom', 'hi');
    $loop->run('session-custom');

    $names = array_map(static fn (ToolDescriptor $d): string => $d->name, $provider->calls[0]['tools']);
    expect($names)->toBe(['upsert_entry']);
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
    $assetId = json_decode($assetCreated->text, true)['data']['id'];

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

it('persists provider-reported input/output token counts on assistant turns', function () {
    $provider = new FakeProvider([
        new ProviderResponse(
            'msg_usage',
            [['type' => 'text', 'text' => 'sure thing']],
            'end_turn',
            ['usage' => ['input_tokens' => 12345, 'output_tokens' => 678]],
            inputTokens: 12345,
            outputTokens: 678,
        ),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->appendUserMessage('session-tokens', 'hi');
    $loop->run('session-tokens');

    /** @var MessageRecord $assistant */
    $assistant = MessageRecord::find()
        ->where(['sessionId' => 'session-tokens', 'role' => 'assistant'])
        ->orderBy(['id' => SORT_ASC])
        ->one();

    expect((int) $assistant->inputTokens)->toBe(12345);
    expect((int) $assistant->outputTokens)->toBe(678);
});

it('auto-compacts the conversation when the last turn passed 95% of the context window', function () {
    // Seed: user prompt + assistant reply that "used" 980 tokens — that's
    // 98%, past the 95% threshold against a 1,000-token simulated window.
    $sessionId = 'session-compact-preflight';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $u = new MessageRecord();
    $u->sessionId = $sessionId;
    $u->role = 'user';
    $u->content = json_encode([['type' => 'text', 'text' => 'first question']]);
    $u->save();

    $a = new MessageRecord();
    $a->sessionId = $sessionId;
    $a->role = 'assistant';
    $a->content = json_encode([['type' => 'text', 'text' => 'first answer']]);
    $a->inputTokens = 980;
    $a->outputTokens = 0;
    $a->save();

    // The user has now sent a follow-up that triggers a new run.
    $u2 = new MessageRecord();
    $u2->sessionId = $sessionId;
    $u2->role = 'user';
    $u2->content = json_encode([['type' => 'text', 'text' => 'second question']]);
    $u2->save();

    $summarizer = new FakeProvider([
        new ProviderResponse(
            'msg_sum',
            [['type' => 'text', 'text' => 'SUMMARY: discussed X and Y.']],
            'end_turn',
        ),
    ]);

    $mainProvider = new FakeProvider([
        new ProviderResponse(
            'msg_next',
            [['type' => 'text', 'text' => 'continuing after summary']],
            'end_turn',
            inputTokens: 50,
            outputTokens: 10,
        ),
    ]);

    $loop = new AgentLoop($mainProvider, $this->registry);
    $loop->setSmallProvider($summarizer);
    $loop->setContextWindow(1000);
    $loop->run($sessionId);

    // A summary row was written. compactionPivotId points at the id of
    // the *last summarized* message (the assistant turn), not the summary
    // row itself — so loadMessages can filter with `>` strict and still
    // include the freshly-written summary on the next load.
    /** @var MessageRecord|null $summary */
    $summary = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'summary'])
        ->one();
    expect($summary)->not->toBeNull();

    $session = SessionRecord::findOne(['id' => $sessionId]);
    expect((int) $session->compactionPivotId)->toBe((int) $a->id);
    expect((int) $session->compactionPivotId)->toBeLessThan((int) $summary->id);

    // The provider saw exactly one user turn: the trailing "second
    // question" with the summary folded in as a system note.
    expect($mainProvider->calls)->toHaveCount(1);
    $sentMessages = $mainProvider->calls[0]['messages'];
    expect($sentMessages)->toHaveCount(1);
    expect($sentMessages[0]['role'])->toBe('user');
    $texts = array_values(array_filter(
        $sentMessages[0]['content'],
        static fn ($b) => ($b['type'] ?? '') === 'text',
    ));
    expect($texts[0]['text'])->toContain('Summary of the conversation so far');
    expect($texts[0]['text'])->toContain('SUMMARY: discussed X and Y.');
    expect($texts[1]['text'])->toBe('second question');
});

it('recovers from a context-length 400 by compacting and retrying once', function () {
    $sessionId = 'session-compact-recovery';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    // Seed enough transcript that a compaction has something to summarize.
    foreach (['ping', 'pong', 'ping again'] as $i => $text) {
        $r = new MessageRecord();
        $r->sessionId = $sessionId;
        $r->role = $i % 2 === 0 ? 'user' : 'assistant';
        $r->content = json_encode([['type' => 'text', 'text' => $text]]);
        $r->save();
    }

    // Build a Guzzle ClientException matching the shape DeepSeek returns
    // when the prompt overruns the model's context window.
    $request = new \GuzzleHttp\Psr7\Request('POST', 'v1/chat/completions');
    $body = '{"error":{"message":"This model\'s maximum context length is 1048576 tokens."}}';
    $response = new \GuzzleHttp\Psr7\Response(400, [], $body);
    $contextError = new \GuzzleHttp\Exception\ClientException(
        'Client error: 400',
        $request,
        $response,
    );

    /** @var \markhuot\craftai\agent\providers\LlmProvider $throwingProvider */
    $throwingProvider = new class($contextError) implements \markhuot\craftai\agent\providers\LlmProvider {
        /** @var list<array{messages: list<array<string, mixed>>, tools: list<\markhuot\craftai\tools\ToolDescriptor>}> */
        public array $calls = [];
        public int $count = 0;

        public function __construct(private readonly \Throwable $err) {}

        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            $this->calls[] = ['messages' => $messages, 'tools' => $tools];
            $this->count++;
            if ($this->count === 1) {
                throw $this->err;
            }
            return new ProviderResponse(
                'msg_after',
                [['type' => 'text', 'text' => 'recovered after compaction']],
                'end_turn',
                inputTokens: 10,
                outputTokens: 3,
            );
        }
    };

    $summarizer = new FakeProvider([
        new ProviderResponse(
            'msg_sum',
            [['type' => 'text', 'text' => 'Summary of pings.']],
            'end_turn',
        ),
    ]);

    $loop = new AgentLoop($throwingProvider, $this->registry);
    $loop->setSmallProvider($summarizer);
    $loop->setContextWindow(1_000_000);
    $loop->run($sessionId);

    // The provider was retried after compaction: 1 failed call + 1 success.
    expect($throwingProvider->count)->toBe(2);

    /** @var MessageRecord|null $summary */
    $summary = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'summary'])
        ->one();
    expect($summary)->not->toBeNull();
    expect($summary->content)->toContain('Summary of pings.');

    // The retry's prompt summarized the pre-cutoff messages (user "ping" +
    // assistant "pong") but preserved the trailing user "ping again" — so
    // the LLM still sees the question that was awaiting an answer.
    $retryMessages = $throwingProvider->calls[1]['messages'];
    // The retry sends exactly one user turn: the trailing "ping again",
    // prepended with the summary as a folded system note.
    expect($retryMessages)->toHaveCount(1);
    expect($retryMessages[0]['role'])->toBe('user');
    $texts = array_values(array_filter(
        $retryMessages[0]['content'],
        static fn ($b) => ($b['type'] ?? '') === 'text',
    ));
    $joined = implode('|', array_map(static fn ($b) => $b['text'], $texts));
    expect($joined)->toContain('Summary of pings.');
    expect($joined)->toContain('ping again');
    // The first user turn ("ping") and the assistant ("pong") were
    // summarized into the summary text and dropped from history.
    expect($joined)->not->toContain('USER:'); // raw transcript markers don't leak
});

it('keeps a tool_use/tool_result pair together when compaction picks the cutoff', function () {
    // Reproduces the failure mode where a tool returned a payload large enough
    // to overrun the context window. The pre-fix compaction picked the last
    // assistant as the cutoff (an assistant with tool_use blocks) and left the
    // matching user tool_result behind the pivot — the retry then sent that
    // orphan tool_result and DeepSeek 400'd with
    // "Messages with role 'tool' must be a response to a preceding message".
    $sessionId = 'session-compact-pair';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    // user -> assistant(tool_use) -> user(tool_result), then a fresh user
    // question that triggers the run.
    $u1 = new MessageRecord();
    $u1->sessionId = $sessionId;
    $u1->role = 'user';
    $u1->content = json_encode([['type' => 'text', 'text' => 'fetch the page']]);
    $u1->save();

    $a1 = new MessageRecord();
    $a1->sessionId = $sessionId;
    $a1->role = 'assistant';
    $a1->content = json_encode([
        ['type' => 'tool_use', 'id' => 'tu_big', 'name' => 'fetch_webpage', 'input' => []],
    ]);
    $a1->save();

    $tr = new MessageRecord();
    $tr->sessionId = $sessionId;
    $tr->role = 'user';
    $tr->content = json_encode([
        ['type' => 'tool_result', 'tool_use_id' => 'tu_big', 'content' => 'huge payload'],
    ]);
    $tr->save();

    $u2 = new MessageRecord();
    $u2->sessionId = $sessionId;
    $u2->role = 'user';
    $u2->content = json_encode([['type' => 'text', 'text' => 'summarize that for me']]);
    $u2->save();

    // Build a 400 ClientException to force the context-length recovery path,
    // which is what triggers compaction on a transcript that ends in a
    // tool_use/tool_result pair.
    $request = new \GuzzleHttp\Psr7\Request('POST', 'v1/chat/completions');
    $body = '{"error":{"message":"This model\'s maximum context length is 1048576 tokens."}}';
    $response = new \GuzzleHttp\Psr7\Response(400, [], $body);
    $contextError = new \GuzzleHttp\Exception\ClientException(
        'Client error: 400',
        $request,
        $response,
    );

    $throwingProvider = new class($contextError) implements LlmProvider {
        /** @var list<array{messages: list<array<string, mixed>>, tools: list<ToolDescriptor>}> */
        public array $calls = [];
        public int $count = 0;
        public function __construct(private readonly \Throwable $err) {}
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            $this->calls[] = ['messages' => $messages, 'tools' => $tools];
            $this->count++;
            if ($this->count === 1) {
                throw $this->err;
            }
            return new ProviderResponse(
                'msg_after',
                [['type' => 'text', 'text' => 'recovered']],
                'end_turn',
                inputTokens: 5,
                outputTokens: 2,
            );
        }
    };

    $summarizer = new FakeProvider([
        new ProviderResponse(
            'msg_sum',
            [['type' => 'text', 'text' => 'Summary of the fetch turn.']],
            'end_turn',
        ),
    ]);

    $loop = new AgentLoop($throwingProvider, $this->registry);
    $loop->setSmallProvider($summarizer);
    $loop->setContextWindow(1_000_000);
    $loop->run($sessionId);

    // The pivot must land on the tool_result row, not on the assistant —
    // otherwise the next run would reload starting with an orphan tool_result.
    $session = SessionRecord::findOne(['id' => $sessionId]);
    expect((int) $session->compactionPivotId)->toBe((int) $tr->id);

    // The retry prompt must NOT contain any orphan tool_result blocks: every
    // tool_result has to be preceded by an assistant tool_use with the same id.
    expect($throwingProvider->count)->toBe(2);
    $retry = $throwingProvider->calls[1]['messages'];
    $openIds = [];
    foreach ($retry as $msg) {
        foreach ($msg['content'] as $block) {
            $type = $block['type'] ?? '';
            if ($msg['role'] === 'assistant' && $type === 'tool_use') {
                $openIds[$block['id']] = true;
            }
            if ($msg['role'] === 'user' && $type === 'tool_result') {
                $id = $block['tool_use_id'] ?? null;
                expect(isset($openIds[$id]))->toBeTrue(
                    'orphan tool_result with id '.var_export($id, true).' in retry prompt'
                );
                unset($openIds[$id]);
            }
        }
    }
});

it('skips messages before the compaction pivot when loading history for the next turn', function () {
    $sessionId = 'session-post-pivot';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    // Pre-pivot history (should be ignored on next run).
    $u1 = new MessageRecord();
    $u1->sessionId = $sessionId;
    $u1->role = 'user';
    $u1->content = json_encode([['type' => 'text', 'text' => 'old user']]);
    $u1->save();

    $a1 = new MessageRecord();
    $a1->sessionId = $sessionId;
    $a1->role = 'assistant';
    $a1->content = json_encode([['type' => 'text', 'text' => 'old assistant']]);
    $a1->save();

    // The summary row marks the pivot. compactionPivotId points at the id
    // of the *last summarized* message (a1), so loadMessages filters with
    // `id > pivot` — the summary itself (which has a higher id) survives
    // and gets folded into the next user turn as a system note.
    $sum = new MessageRecord();
    $sum->sessionId = $sessionId;
    $sum->role = 'summary';
    $sum->content = json_encode([['type' => 'text', 'text' => 'condensed earlier conversation']]);
    $sum->save();

    $session->compactionPivotId = (int) $a1->id;
    $session->save();

    // Post-pivot: a new user turn that triggers the next run.
    $u2 = new MessageRecord();
    $u2->sessionId = $sessionId;
    $u2->role = 'user';
    $u2->content = json_encode([['type' => 'text', 'text' => 'new question']]);
    $u2->save();

    $provider = new FakeProvider([
        new ProviderResponse('msg_a', [['type' => 'text', 'text' => 'new answer']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    $sent = $provider->calls[0]['messages'];
    // Only one user turn went to the provider — the summary got folded into
    // it as a text block, and the pre-pivot messages were skipped entirely.
    expect($sent)->toHaveCount(1);
    expect($sent[0]['role'])->toBe('user');
    $texts = array_values(array_filter(
        $sent[0]['content'],
        static fn ($b) => ($b['type'] ?? '') === 'text',
    ));
    $joined = implode('|', array_map(static fn ($b) => $b['text'], $texts));
    expect($joined)->toContain('condensed earlier conversation');
    expect($joined)->toContain('new question');
    expect($joined)->not->toContain('old user');
    expect($joined)->not->toContain('old assistant');
});

it('intercepts a /compact slash command and runs compaction without calling the LLM', function () {
    $sessionId = 'session-slash-compact';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    // Seed a complete prior turn so compact() has something to summarize.
    $u = new MessageRecord();
    $u->sessionId = $sessionId;
    $u->role = 'user';
    $u->content = json_encode([['type' => 'text', 'text' => 'tell me about birds']]);
    $u->save();

    $a = new MessageRecord();
    $a->sessionId = $sessionId;
    $a->role = 'assistant';
    $a->content = json_encode([['type' => 'text', 'text' => 'they fly and sing']]);
    $a->save();

    // The user's latest message is the slash command itself.
    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/compact']]);
    $cmd->save();

    // FakeProvider with zero scripted responses — if the loop calls it,
    // the test fails. That's how we assert the slash command short-circuited
    // the normal flow.
    $mainProvider = new FakeProvider([]);

    $summarizer = new FakeProvider([
        new ProviderResponse(
            'msg_sum',
            [['type' => 'text', 'text' => 'Summary: discussed birds.']],
            'end_turn',
        ),
    ]);

    $loop = new AgentLoop($mainProvider, $this->registry);
    $loop->setSmallProvider($summarizer);
    $loop->run($sessionId);

    expect($mainProvider->calls)->toHaveCount(0);

    /** @var MessageRecord|null $summary */
    $summary = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'summary'])
        ->one();
    expect($summary)->not->toBeNull();
    expect($summary->content)->toContain('Summary: discussed birds.');

    // The slash command path writes an assistant feedback message so the
    // chat surface renders confirmation that the command ran.
    /** @var list<MessageRecord> $records */
    $records = MessageRecord::find()
        ->where(['sessionId' => $sessionId])
        ->orderBy(['id' => SORT_DESC])
        ->all();
    $confirmation = $records[0];
    expect($confirmation->role)->toBe('assistant');
    $confirmText = json_decode($confirmation->content, true)[0]['text'] ?? '';
    expect($confirmText)->toContain('compacted');
});

it('reports an unknown slash command without calling the LLM', function () {
    $sessionId = 'session-slash-unknown';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/banana']]);
    $cmd->save();

    $provider = new FakeProvider([]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    expect($provider->calls)->toHaveCount(0);

    /** @var MessageRecord|null $reply */
    $reply = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'assistant'])
        ->orderBy(['id' => SORT_DESC])
        ->one();
    $text = json_decode($reply->content, true)[0]['text'] ?? '';
    expect($text)->toContain('Unknown command');
    expect($text)->toContain('/banana');
    expect($text)->toContain('/compact');
});

it('warns when /compact runs against a fresh session with no assistant turn', function () {
    $sessionId = 'session-slash-empty';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    // Only a slash command — no prior assistant to summarize.
    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/compact']]);
    $cmd->save();

    $provider = new FakeProvider([]);
    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    expect($provider->calls)->toHaveCount(0);

    /** @var MessageRecord|null $reply */
    $reply = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'assistant'])
        ->orderBy(['id' => SORT_DESC])
        ->one();
    $text = json_decode($reply->content, true)[0]['text'] ?? '';
    expect($text)->toContain('Nothing to compact');
});

it('persists a tool_result even when the tool returns invalid UTF-8 bytes', function () {
    // Regression: a tool that returned non-UTF-8 bytes (e.g. fetch_webpage on
    // a Windows-1252 page) used to abort the entire turn at json_encode and
    // leave the conversation with an unanswered tool_use that the next
    // provider call would reject. Now we scrub invalid bytes to U+FFFD so the
    // loop survives.
    $registry = new ToolRegistry();
    $registry->register(BadUtf8Tool::class);

    $provider = new FakeProvider([
        new ProviderResponse(
            'msg_1',
            [['type' => 'tool_use', 'id' => 'tu_bad', 'name' => 'bad_utf8_tool', 'input' => []]],
            'tool_use',
        ),
        new ProviderResponse('msg_2', [['type' => 'text', 'text' => 'recovered.']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $registry);
    $loop->appendUserMessage('session-badutf', 'do the thing');
    $loop->run('session-badutf');

    $records = MessageRecord::find()
        ->where(['sessionId' => 'session-badutf'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    // user prompt, assistant tool_use, user tool_result, assistant final text.
    expect($records)->toHaveCount(4);
    expect($records[2]->role)->toBe('user');

    // The stored content must be valid UTF-8 so subsequent json_decode (and
    // provider calls) succeed.
    expect(mb_check_encoding($records[2]->content, 'UTF-8'))->toBeTrue();

    $toolResult = json_decode($records[2]->content, true)[0];
    expect($toolResult['type'])->toBe('tool_result');
    expect($toolResult['tool_use_id'])->toBe('tu_bad');
    // Bad bytes are replaced with U+FFFD; the surrounding ASCII survives.
    expect($toolResult['content'])->toContain('hello');
    expect($toolResult['content'])->toContain('world');
    expect($toolResult['content'])->toContain('end');
    expect(mb_check_encoding($toolResult['content'], 'UTF-8'))->toBeTrue();

    // The loop must have continued past the bad tool_result.
    expect($records[3]->role)->toBe('assistant');
    $final = json_decode($records[3]->content, true)[0];
    expect($final['text'])->toBe('recovered.');
});
