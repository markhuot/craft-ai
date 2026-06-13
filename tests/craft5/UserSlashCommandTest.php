<?php

use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\ToolRegistry;

class FakeUserCommandProvider implements LlmProvider
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
            throw new \RuntimeException('FakeUserCommandProvider exhausted scripted responses');
        }
        return $next;
    }
}

beforeEach(function () {
    $this->registry = new ToolRegistry();

    // Reset plugin settings to a known commands list for each test so we
    // don't accidentally lean on the seeded defaults — the dispatcher
    // needs to match exactly the name we configure.
    $settings = Plugin::getInstance()->getSettings();
    expect($settings)->toBeInstanceOf(Settings::class);
    $settings->setCommands([
        ['name' => 'translate', 'prompt' => 'Translate the current entry into the language the user named.'],
        ['name' => 'editorial-review', 'prompt' => 'Review the current entry. {args}'],
        ['name' => 'disabled-one', 'prompt' => 'Should never fire', 'enabled' => false],
    ]);
});

it('exposes user-defined commands in availableSlashCommands() alongside the built-ins', function () {
    $catalog = AgentLoop::availableSlashCommands();

    // Built-ins still there
    expect($catalog)->toHaveKey('compact');
    expect($catalog)->toHaveKey('review');

    // User commands surface
    expect($catalog)->toHaveKey('translate');
    expect($catalog)->toHaveKey('editorial-review');

    // Disabled commands are filtered out so the autocomplete doesn't
    // tease an editor with a command that can't actually run.
    expect($catalog)->not->toHaveKey('disabled-one');
});

it('rewrites a user command into its configured prompt and falls through to the LLM', function () {
    $sessionId = 'session-user-cmd-basic';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/translate']]);
    $cmd->save();

    $provider = new FakeUserCommandProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    $loop = new AgentLoop($provider, $this->registry);
    $loop->run($sessionId);

    // Provider was called — the user command falls through to the LLM
    // like /review does, rather than being terminal like /compact.
    expect($provider->calls)->toHaveCount(1);

    $rewritten = MessageRecord::findOne(['id' => $cmd->id]);
    $body = json_decode($rewritten->content, true)[0]['text'];
    expect($body)->not->toContain('/translate');
    expect($body)->toContain('Translate the current entry');
});

it('substitutes the {args} placeholder when the prompt declares one', function () {
    $sessionId = 'session-user-cmd-args';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/editorial-review focus on tone']]);
    $cmd->save();

    $provider = new FakeUserCommandProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    (new AgentLoop($provider, $this->registry))->run($sessionId);

    $rewritten = MessageRecord::findOne(['id' => $cmd->id]);
    $body = json_decode($rewritten->content, true)[0]['text'];
    expect($body)->toContain('Review the current entry. focus on tone');
    expect($body)->not->toContain('{args}');
});

it('appends args under an "Additional input" header when the prompt has no placeholder', function () {
    Plugin::getInstance()->getSettings()->setCommands([
        ['name' => 'translate', 'prompt' => 'Translate the current entry.'],
    ]);

    $sessionId = 'session-user-cmd-append-args';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/translate to French']]);
    $cmd->save();

    $provider = new FakeUserCommandProvider([
        new ProviderResponse('msg_1', [['type' => 'text', 'text' => 'ok']], 'end_turn'),
    ]);

    (new AgentLoop($provider, $this->registry))->run($sessionId);

    $rewritten = MessageRecord::findOne(['id' => $cmd->id]);
    $body = json_decode($rewritten->content, true)[0]['text'];
    expect($body)->toContain('Translate the current entry.');
    expect($body)->toContain('Additional input: to French');
});

it('reports unknown slash commands without crashing the loop', function () {
    $sessionId = 'session-user-cmd-unknown';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/no-such-command']]);
    $cmd->save();

    $provider = new FakeUserCommandProvider([]);

    (new AgentLoop($provider, $this->registry))->run($sessionId);

    // Unknown commands are terminal — the LLM was not invoked.
    expect($provider->calls)->toHaveCount(0);

    /** @var MessageRecord|null $reply */
    $reply = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'assistant'])
        ->orderBy(['id' => SORT_DESC])
        ->one();
    expect($reply)->not->toBeNull();
    expect($reply->content)->toContain('Unknown command');
    // The reply lists the available commands, including our user ones.
    expect($reply->content)->toContain('/translate');
});

it('ignores disabled user commands during dispatch', function () {
    $sessionId = 'session-user-cmd-disabled';
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->userId = 1;
    $session->save();

    $cmd = new MessageRecord();
    $cmd->sessionId = $sessionId;
    $cmd->role = 'user';
    $cmd->content = json_encode([['type' => 'text', 'text' => '/disabled-one']]);
    $cmd->save();

    $provider = new FakeUserCommandProvider([]);

    (new AgentLoop($provider, $this->registry))->run($sessionId);

    expect($provider->calls)->toHaveCount(0);

    $reply = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'assistant'])
        ->orderBy(['id' => SORT_DESC])
        ->one();
    expect($reply->content)->toContain('Unknown command');
});
