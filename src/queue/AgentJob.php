<?php

namespace markhuot\craftai\queue;

use Craft;
use craft\elements\User;
use craft\queue\BaseJob;
use markhuot\craftai\Plugin;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;

class AgentJob extends BaseJob
{
    public string $sessionId = '';

    public string $userMessage = '';

    /** Originating Craft user; restored on the queue worker so tool permission checks see the right identity. */
    public ?int $userId = null;

    /**
     * @param \yii\queue\Queue $queue
     */
    public function execute($queue): void
    {
        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);

        if ($this->userId !== null) {
            $user = User::find()->id($this->userId)->one();
            if ($user instanceof User) {
                Craft::$app->getUser()->setIdentity($user);
            }
        }

        $this->setActive(true);
        $this->ensureTitle();

        try {
            $loop->run($this->sessionId, $this->userMessage);
        } catch (\Throwable $e) {
            $record = new MessageRecord();
            $record->sessionId = $this->sessionId;
            $record->role = 'assistant';
            $record->content = json_encode([[
                'type' => 'error',
                'text' => $e->getMessage(),
            ]], JSON_THROW_ON_ERROR);
            $record->save();

            throw $e;
        } finally {
            $this->setActive(false);
        }
    }

    private function ensureTitle(): void
    {
        $session = SessionRecord::findOne(['id' => $this->sessionId]);

        if ($session === null || ($session->title !== null && $session->title !== '')) {
            return;
        }

        try {
            $provider = Plugin::getInstance()->getSmallModelProvider();
            $response = $provider->createMessage(
                messages: [[
                    'role' => 'user',
                    'content' => "Summarize the following user request as a short conversation title of 5 to 10 words. Respond with only the title text — no quotes, no punctuation at the end, no preamble.\n\nUser request:\n".$this->userMessage,
                ]],
            );

            $title = '';
            foreach ($response->content as $block) {
                if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                    $title .= $block['text'];
                }
            }

            $title = trim($title);
            $title = trim($title, "\"'");

            if ($title === '') {
                return;
            }

            if (mb_strlen($title) > 255) {
                $title = mb_substr($title, 0, 255);
            }

            $session->title = $title;
            $session->save();
        } catch (\Throwable) {
            // Title generation is best-effort; never block the agent loop.
        }
    }

    private function setActive(bool $active): void
    {
        $session = SessionRecord::findOne(['id' => $this->sessionId]);

        if ($session === null) {
            $session = new SessionRecord();
            $session->id = $this->sessionId;
        }

        $session->active = $active;
        $session->save();
    }

    protected function defaultDescription(): ?string
    {
        return 'Processing AI agent conversation';
    }
}
