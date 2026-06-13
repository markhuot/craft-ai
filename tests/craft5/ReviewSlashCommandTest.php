<?php

use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Local minimal fake provider for slash-command tests. Mirrors the
 * AgentLoopTest one but kept distinct so this file doesn't depend on
 * load order with another test file.
 */
class FakeReviewProvider implements LlmProvider
{
    /** @var list<ProviderResponse> */
    public array $responses;

    /** @var list<array{messages: list<array<string, mixed>>, tools: array}> */
    public array $calls = [];

    /** @param list<ProviderResponse> $responses */
    public function __construct(array $responses) { $this->responses = $responses; }

    public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \RuntimeException('FakeReviewProvider exhausted scripted responses');
        }
        return $next;
    }
}

beforeEach(function () {
    $this->registry = new ToolRegistry();
});

it('expands /review entry:N into a verbose prompt and falls through to the LLM', function () {
    $sessionId = 'session-review-entry';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/review entry:42']]);
    $cmd->save();

    $provider = new FakeReviewProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'Will review.']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    // /review must pass through to the LLM, so the provider was called.
    expect($provider->calls)->toHaveCount(1);

    // The rewritten user message should be the expanded prompt referencing
    // entry #42 + the leave_comment tool — NOT the literal "/review" text.
    $rewritten = MessageRecord::findOne(['id' => $cmd->id]);
    $body = json_decode($rewritten->content, true)[0]['text'];
    expect($body)->not->toContain('/review');
    expect($body)->toContain('entry #42');
    expect($body)->toContain('leave_comment');
    expect($body)->toContain('get_entry');

    // A system context note explaining the review session should be appended.
    /** @var MessageRecord|null $sysNote */
    $sysNote = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'system'])
        ->one();
    expect($sysNote)->not->toBeNull();
    expect($sysNote->content)->toContain('Review session started');
});

it('expands /review draft:N to target the draft and use get_draft', function () {
    $sessionId = 'session-review-draft';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/review draft:7']]);
    $cmd->save();

    $provider = new FakeReviewProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    $rewritten = MessageRecord::findOne(['id' => $cmd->id]);
    $body = json_decode($rewritten->content, true)[0]['text'];
    expect($body)->toContain('draft #7');
    expect($body)->toContain('get_draft');
    expect($body)->toContain('draftId: 7');
});

it('treats a bare /review 123 as an entry reference', function () {
    $sessionId = 'session-review-bare';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/review 99']]);
    $cmd->save();

    $provider = new FakeReviewProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    $rewritten = MessageRecord::findOne(['id' => $cmd->id]);
    $body = json_decode($rewritten->content, true)[0]['text'];
    expect($body)->toContain('entry #99');
});

it('asks for clarification when /review has no parseable argument, and does not call the LLM', function () {
    $sessionId = 'session-review-empty';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/review']]);
    $cmd->save();

    $provider = new FakeReviewProvider([]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    expect($provider->calls)->toHaveCount(0);

    /** @var MessageRecord|null $reply */
    $reply = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'assistant'])
        ->orderBy(['id' => SORT_DESC])
        ->one();
    $text = json_decode($reply->content, true)[0]['text'] ?? '';
    expect($text)->toContain('explicit target');
    expect($text)->toContain('entry:');
    expect($text)->toContain('draft:');
});

it('exposes /review in the available slash commands payload', function () {
    $commands = AgentLoop::availableSlashCommands();
    expect($commands)->toHaveKey('review');
    expect($commands['review']['takesArgs'])->toBeTrue();
});
