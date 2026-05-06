<?php

use markhuot\craftai\Plugin;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;

function rebindProvider(LlmProvider $provider): void
{
    Craft::$container->setSingleton(LlmProvider::class, fn () => $provider);

    // AgentLoop is itself a cached singleton; resetting it forces the
    // container to inject the provider above on next get().
    Craft::$container->setSingleton(AgentLoop::class, fn () => new AgentLoop(
        Craft::$container->get(LlmProvider::class),
        Plugin::getInstance()->getToolRegistry(),
    ));
}

it('runs the agent loop with the configured LlmProvider when executed', function () {
    rebindProvider(new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_x', [['type' => 'text', 'text' => 'pong']], 'end_turn');
        }
    });

    $userMessage = new MessageRecord();
    $userMessage->sessionId = 'job-1';
    $userMessage->role = 'user';
    $userMessage->content = json_encode([['type' => 'text', 'text' => 'ping']]);
    $userMessage->save();

    $job = new AgentJob([
        'sessionId' => 'job-1',
    ]);

    $job->execute(Craft::$app->getQueue());

    $records = MessageRecord::find()
        ->where(['sessionId' => 'job-1'])
        ->orderBy(['id' => SORT_ASC])
        ->all();

    expect($records)->toHaveCount(2);
    expect($records[0]->role)->toBe('user');
    expect($records[1]->role)->toBe('assistant');

    $assistantContent = json_decode($records[1]->content, true);
    expect($assistantContent[0]['text'])->toBe('pong');
});

it('truncates the catch-block error before persisting so the failure path itself can finish', function () {
    // Real-world incident: a tool returned ~205KB of HTML. The first INSERT
    // failed with "Data too long for column 'content'", and the original
    // catch tried to persist the entire SQL exception (which embedded the
    // 205KB INSERT) — overflowing the same column on the way out and
    // dropping the failure record entirely. The truncation here keeps the
    // error visible to the user no matter how huge the underlying message.
    $hugeMessage = str_repeat('A', 100_000);
    rebindProvider(new class($hugeMessage) implements LlmProvider {
        public function __construct(private readonly string $message) {}

        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            throw new \RuntimeException($this->message);
        }
    });

    $userMessage = new MessageRecord();
    $userMessage->sessionId = 'job-huge-error';
    $userMessage->role = 'user';
    $userMessage->content = json_encode([['type' => 'text', 'text' => 'go']]);
    $userMessage->save();

    $job = new AgentJob(['sessionId' => 'job-huge-error']);

    try {
        $job->execute(Craft::$app->getQueue());
    } catch (\Throwable) {
        // Expected — the inner exception is rethrown after persisting.
    }

    /** @var MessageRecord $errorRecord */
    $errorRecord = MessageRecord::find()
        ->where(['sessionId' => 'job-huge-error', 'role' => 'assistant'])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    expect($errorRecord)->not->toBeNull();
    $blocks = json_decode($errorRecord->content, true);
    expect($blocks[0]['type'])->toBe('error');
    expect(strlen($blocks[0]['text']))->toBeLessThanOrEqual(4500);
    expect($blocks[0]['text'])->toContain('… [truncated]');
});

it('clears a stale stopRequested flag when starting a new run', function () {
    rebindProvider(new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_y', [['type' => 'text', 'text' => 'fresh']], 'end_turn');
        }
    });

    $session = new SessionRecord();
    $session->id = 'job-stale-stop';
    $session->active = false;
    // Leftover from a previous interrupted run.
    $session->stopRequested = true;
    $session->save();

    $userMessage = new MessageRecord();
    $userMessage->sessionId = 'job-stale-stop';
    $userMessage->role = 'user';
    $userMessage->content = json_encode([['type' => 'text', 'text' => 'go again']]);
    $userMessage->save();

    $job = new AgentJob([
        'sessionId' => 'job-stale-stop',
    ]);

    $job->execute(Craft::$app->getQueue());

    $reloaded = SessionRecord::findOne(['id' => 'job-stale-stop']);
    expect((bool) $reloaded->stopRequested)->toBeFalse();
    expect((bool) $reloaded->active)->toBeFalse();

    // The loop completed normally because the stale flag was cleared before
    // the loop's first stop check.
    $records = MessageRecord::find()
        ->where(['sessionId' => 'job-stale-stop'])
        ->orderBy(['id' => SORT_ASC])
        ->all();
    expect($records)->toHaveCount(2);
    $assistantContent = json_decode($records[1]->content, true);
    expect($assistantContent[0]['text'])->toBe('fresh');
});
