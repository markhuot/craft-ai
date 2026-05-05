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
