<?php

use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;

it('runs the agent loop with the configured LlmProvider when executed', function () {
    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_x', [['type' => 'text', 'text' => 'pong']], 'end_turn');
        }
    });

    $job = new AgentJob([
        'sessionId' => 'job-1',
        'userMessage' => 'ping',
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
