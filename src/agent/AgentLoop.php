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
        private readonly ToolContext $toolContext = new ToolContext(),
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

    /**
     * Persist a synthesized note as a `system` message in the conversation.
     * The widget sends a fresh page-context payload only when the user has
     * navigated to a new page since their last message; this method renders
     * that payload to prose so the user can see "what the agent knows" and
     * the agent has stable context for the user turns that follow.
     *
     * The raw payload is intentionally discarded — the prose is the record.
     */
    public function appendSystemContext(string $sessionId, string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }
        $this->saveMessage($sessionId, 'system', [['type' => 'text', 'text' => $note]]);
    }

    public function run(string $sessionId): void
    {
        $messages = $this->ensureToolResults($this->loadMessages($sessionId));
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

                $toolUseId = is_string($block['id'] ?? null) ? $block['id'] : null;

                $this->toolContext->begin($sessionId, $toolUseId, ClientType::CP);
                try {
                    $output = $this->registry->execute($name, $input);
                } finally {
                    $this->toolContext->end();
                }

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

        $messages = [];
        // Buffer of pending system-context text blocks. Anthropic's messages
        // API only allows user/assistant in `messages[]`, and we can't move
        // them into the top-level `system` parameter mid-conversation without
        // losing their ordering relative to the user turns they describe. So
        // we fold the buffered system text into the next user turn — the
        // model reads it as part of the user's message, with a clear delimiter.
        $pendingSystem = [];

        foreach ($records as $record) {
            /** @var list<array<string, mixed>> $content */
            $content = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);

            if ($record->role === 'system') {
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                        $pendingSystem[] = ['type' => 'text', 'text' => $block['text']];
                    }
                }
                continue;
            }

            if ($record->role === 'user') {
                if ($pendingSystem !== []) {
                    $content = array_merge($pendingSystem, $content);
                    $pendingSystem = [];
                }

                if ($record->assetIds !== null && $record->assetIds !== '') {
                    /** @var list<int> $assetIds */
                    $assetIds = json_decode($record->assetIds, true, 512, JSON_THROW_ON_ERROR);
                    $annotation = $this->assetAnnotation($assetIds);
                    if ($annotation !== null) {
                        $content[] = ['type' => 'text', 'text' => $annotation];
                    }
                }
            }

            $messages[] = [
                'role' => $record->role,
                'content' => $content,
            ];
        }

        // Any pending system rows that didn't get a follow-up user message —
        // e.g. an interrupted send — get attached as a trailing synthetic
        // user turn so the LLM still sees the context rather than dropping it.
        if ($pendingSystem !== []) {
            $messages[] = ['role' => 'user', 'content' => $pendingSystem];
        }

        return $messages;
    }

    /**
     * Anthropic and OpenAI both reject conversations where an assistant
     * `tool_use` block isn't immediately followed by a user turn containing
     * `tool_result` blocks for every tool_use_id. That invariant can break
     * when the queue worker fails between executing a tool and persisting
     * its result — e.g. a 1406 "Data too long" on the messages table — and
     * leaves an orphan tool_use without a tool_result.
     *
     * This method walks the assembled message list and synthesizes an error
     * tool_result for any orphan, so a stale broken session can recover on
     * its own when the user sends a new message. The synthesized result is
     * marked is_error=true and explains the situation to the model so it
     * doesn't blindly retry.
     *
     * @param list<array{role: string, content: list<array<string, mixed>>}> $messages
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function ensureToolResults(array $messages): array
    {
        $healed = [];
        $count = count($messages);
        $i = 0;

        while ($i < $count) {
            $message = $messages[$i];
            $healed[] = $message;

            if ($message['role'] !== 'assistant') {
                $i++;
                continue;
            }

            $orphanIds = [];
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_use' && is_string($block['id'] ?? null)) {
                    $orphanIds[$block['id']] = true;
                }
            }

            if ($orphanIds === []) {
                $i++;
                continue;
            }

            $next = $messages[$i + 1] ?? null;
            if ($next !== null && $next['role'] === 'user') {
                foreach ($next['content'] as $block) {
                    if (($block['type'] ?? '') === 'tool_result' && is_string($block['tool_use_id'] ?? null)) {
                        unset($orphanIds[$block['tool_use_id']]);
                    }
                }
            }

            if ($orphanIds === []) {
                $i++;
                continue;
            }

            $synthetic = [];
            foreach (array_keys($orphanIds) as $toolUseId) {
                $synthetic[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content' => 'The tool did not return a result — the worker likely failed mid-execution. Try a different approach or summarize what you have so far.',
                    'is_error' => true,
                ];
            }

            if ($next !== null && $next['role'] === 'user') {
                // Prepend the synthesized results onto the existing user turn
                // so its tool_result blocks (if any) still get sent.
                $merged = array_merge($synthetic, $next['content']);
                $healed[] = ['role' => 'user', 'content' => $merged];
                $i += 2;
                continue;
            }

            $healed[] = ['role' => 'user', 'content' => $synthetic];
            $i++;
        }

        return $healed;
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
