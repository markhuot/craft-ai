<?php

namespace markhuot\craftai\queue;

use Craft;
use craft\queue\BaseJob;
use markhuot\craftai\agent\AgentLoop;

class AgentJob extends BaseJob
{
    public string $sessionId = '';

    public string $userMessage = '';

    /**
     * @param \yii\queue\Queue $queue
     */
    public function execute($queue): void
    {
        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);
        $loop->run($this->sessionId, $this->userMessage);
    }

    protected function defaultDescription(): ?string
    {
        return 'Processing AI agent conversation';
    }
}
