<?php

namespace markhuot\craftai\agent;

use craft\elements\Asset;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\ToolRegistry;

class AgentLoop
{
    public function __construct(
        private readonly LlmProvider $provider,
        private readonly ToolRegistry $registry,
    ) {}

    /**
     * Persist a user message so the CP transcript reflects it immediately,
     * before the (possibly queued) agent loop picks it up.
     *
     * @param list<int> $assetIds  Optional asset IDs the user attached to the message.
     *                              Stored alongside the message and surfaced to the LLM
     *                              as a text annotation so the agent can request the
     *                              asset's contents through tools if needed.
     */
    public function appendUserMessage(string $sessionId, string $userMessage, array $assetIds = []): void
    {
        $this->saveMessage($sessionId, 'user', [['type' => 'text', 'text' => $userMessage]], assetIds: $assetIds);
    }

    public function run(string $sessionId): void
    {
        $messages = $this->loadMessages($sessionId);
        $tools = $this->registry->descriptors(onlyAllowed: true);

        while (true) {
            if ($this->isStopRequested($sessionId)) {
                $this->recordStopMarker($sessionId);
                return;
            }

            $response = $this->provider->createMessage($messages, $tools);

            $this->saveMessage($sessionId, 'assistant', $response->content, $response->raw);
            $messages[] = ['role' => 'assistant', 'content' => $response->content];

            if ($response->stopReason !== 'tool_use') {
                break;
            }

            // Re-check between LLM response and tool execution. If the user
            // hit Stop while we were waiting on the model, short-circuit the
            // tools but still emit tool_result blocks so the saved transcript
            // matches every tool_use id — otherwise the next provider call
            // would reject the malformed conversation.
            $stopMidTurn = $this->isStopRequested($sessionId);

            $toolResults = [];
            foreach ($response->content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $name = $block['name'] ?? null;
                if (! is_string($name)) {
                    continue;
                }

                if ($stopMidTurn) {
                    $toolResults[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content' => 'Stopped by user.',
                        'is_error' => true,
                    ];
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

            if ($stopMidTurn) {
                $this->recordStopMarker($sessionId);
                return;
            }
        }
    }

    private function isStopRequested(string $sessionId): bool
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);

        return $session !== null && (bool) $session->stopRequested;
    }

    private function recordStopMarker(string $sessionId): void
    {
        $this->saveMessage($sessionId, 'assistant', [[
            'type' => 'text',
            'text' => 'Stopped by user.',
        ]]);
    }

    /**
     * @param list<array<string, mixed>> $content
     * @param array<string, mixed>|null $rawResponse Full provider payload, persisted
     *        on assistant turns to retain provider-specific fields (e.g.
     *        DeepSeek `reasoning_content`) that the canonical block format drops.
     * @param list<int> $assetIds
     */
    private function saveMessage(string $sessionId, string $role, array $content, ?array $rawResponse = null, array $assetIds = []): void
    {
        $record = new MessageRecord();
        $record->sessionId = $sessionId;
        $record->role = $role;
        $record->content = json_encode($content, JSON_THROW_ON_ERROR);
        $record->rawResponse = $rawResponse === null
            ? null
            : json_encode($rawResponse, JSON_THROW_ON_ERROR);
        $record->assetIds = $assetIds === []
            ? null
            : json_encode(array_map('intval', $assetIds), JSON_THROW_ON_ERROR);
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

        return array_map(function (MessageRecord $record): array {
            /** @var list<array<string, mixed>> $content */
            $content = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);

            if ($record->role === 'user' && $record->assetIds !== null && $record->assetIds !== '') {
                /** @var list<int> $assetIds */
                $assetIds = json_decode($record->assetIds, true, 512, JSON_THROW_ON_ERROR);
                $annotation = $this->assetAnnotation($assetIds);
                if ($annotation !== null) {
                    $content[] = ['type' => 'text', 'text' => $annotation];
                }
            }

            return [
                'role' => $record->role,
                'content' => $content,
            ];
        }, $records);
    }

    /**
     * Render attached asset IDs as a short reference block the LLM can read.
     * We deliberately do not embed the raw file contents — the agent can call
     * `get_asset` (or any other asset tool) to fetch them on demand.
     *
     * @param list<int> $assetIds
     */
    private function assetAnnotation(array $assetIds): ?string
    {
        if ($assetIds === []) {
            return null;
        }

        $assets = Asset::find()->id($assetIds)->status(null)->all();
        $byId = [];
        foreach ($assets as $asset) {
            $byId[$asset->id] = $asset;
        }

        $lines = [];
        foreach ($assetIds as $id) {
            $asset = $byId[$id] ?? null;
            if ($asset === null) {
                $lines[] = "- asset id {$id} (not found)";
                continue;
            }

            $kind = $asset->kind ?: 'file';
            $filename = $asset->filename ?: 'unknown';
            $mime = $asset->getMimeType() ?: 'application/octet-stream';
            $lines[] = "- asset id {$asset->id}: {$filename} ({$kind}, {$mime})";
        }

        return "[The user attached the following assets to this message. Use the `get_asset` tool to inspect any of them if needed.]\n".implode("\n", $lines);
    }
}
