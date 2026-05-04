<?php

namespace markhuot\craftai\agent;

use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\tools\ToolRegistry;

class AgentLoop
{
    public function __construct(
        private readonly LlmProvider $provider,
        private readonly ToolRegistry $registry,
    ) {}

    public function run(string $sessionId, string $userMessage): void
    {
        $this->saveMessage($sessionId, 'user', [['type' => 'text', 'text' => $userMessage]]);

        $messages = $this->loadMessages($sessionId);
        $tools = $this->registry->descriptors(onlyAllowed: true);

        while (true) {
            $response = $this->provider->createMessage($messages, $tools);

            $this->saveMessage($sessionId, 'assistant', $response->content, $response->raw);
            $messages[] = ['role' => 'assistant', 'content' => $response->content];

            if ($response->stopReason !== 'tool_use') {
                break;
            }

            $toolResults = [];
            foreach ($response->content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $name = $block['name'] ?? null;
                if (! is_string($name)) {
                    continue;
                }

                /** @var array<string, mixed> $input */
                $input = $block['input'] ?? [];

                $output = $this->registry->execute($name, $input);

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => $output->text,
                    'is_error' => $output->isError,
                ];
            }

            $this->saveMessage($sessionId, 'user', $toolResults);
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }
    }

    /**
     * @param list<array<string, mixed>> $content
     * @param array<string, mixed>|null $rawResponse Full provider payload, persisted
     *        on assistant turns to retain provider-specific fields (e.g.
     *        DeepSeek `reasoning_content`) that the canonical block format drops.
     */
    private function saveMessage(string $sessionId, string $role, array $content, ?array $rawResponse = null): void
    {
        $record = new MessageRecord();
        $record->sessionId = $sessionId;
        $record->role = $role;
        $record->content = json_encode($content, JSON_THROW_ON_ERROR);
        $record->rawResponse = $rawResponse === null
            ? null
            : json_encode($rawResponse, JSON_THROW_ON_ERROR);
        $record->save();
    }

    /**
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function loadMessages(string $sessionId): array
    {
        /** @var list<MessageRecord> $records */
        $records = MessageRecord::find()
            ->where(['sessionId' => $sessionId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(static function (MessageRecord $record): array {
            /** @var list<array<string, mixed>> $content */
            $content = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);

            return [
                'role' => $record->role,
                'content' => $content,
            ];
        }, $records);
    }
}
