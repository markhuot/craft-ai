<?php

namespace markhuot\craftai\queue;

use craft\helpers\App;
use craft\queue\BaseJob;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\AnthropicClient;
use markhuot\craftai\Plugin;

class AgentJob extends BaseJob
{
    public string $sessionId = '';

    public string $userMessage = '';

    /**
     * @param \yii\queue\Queue $queue
     */
    public function execute($queue): void
    {
        $apiKey = App::env('ANTHROPIC_API_KEY');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY environment variable is not set');
        }

        $client = new AnthropicClient($apiKey);
        $registry = Plugin::getInstance()->getToolRegistry();

        $loop = new AgentLoop($client, $registry);
        $loop->run($this->sessionId, $this->userMessage);
    }

    protected function defaultDescription(): ?string
    {
        return 'Processing AI agent conversation';
    }
}
