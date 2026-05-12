<?php

namespace markhuot\craftai\agent;

use craft\elements\Asset;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\Plugin;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\ToolRegistry;

class AgentLoop
{
    /**
     * Fraction of the configured context window at which we proactively
     * summarize the conversation before the next provider call. Tuned by
     * hand: 0.95 still gives the next request room to grow with tool
     * outputs without immediately tripping the provider's hard limit.
     */
    private const COMPACTION_THRESHOLD = 0.95;

    /**
     * Lazy reference to the small-model provider. Resolved on first compact()
     * to keep the constructor simple (DI binds the main LlmProvider, not the
     * small one — that's pulled from Plugin settings).
     */
    private ?LlmProvider $smallProviderOverride = null;

    /**
     * Test-only override for the context window. Without this, tests would
     * have to mutate the on-disk config file to exercise the compaction path.
     */
    private ?int $contextWindowOverride = null;

    public function __construct(
        private readonly LlmProvider $provider,
        private readonly ToolRegistry $registry,
        private readonly ToolContext $toolContext = new ToolContext(),
    ) {}

    /**
     * Test seam: inject a fake small-model provider so unit tests can drive
     * the compaction path without configuring the real Plugin settings.
     */
    public function setSmallProvider(LlmProvider $provider): void
    {
        $this->smallProviderOverride = $provider;
    }

    /**
     * Test seam: override the context-window lookup so tests can trigger
     * the auto-compaction threshold with small token counts.
     */
    public function setContextWindow(?int $contextWindow): void
    {
        $this->contextWindowOverride = $contextWindow;
    }

    /**
     * Built-in slash commands. Each entry is a name → metadata pair used by
     * both the dispatcher (server-side) and the front-end autocomplete
     * menu. Keep the array shape in sync with `availableSlashCommands()`
     * which the chat controller exposes to the React UI — the source of
     * truth for "what commands exist" lives here.
     *
     * Adding a command:
     *   1. Add it to this array.
     *   2. Add a `case` to {@see dispatchSlashCommand}.
     *   3. The front-end picks it up automatically via the bootstrap.
     */
    public const SLASH_COMMANDS = [
        'compact' => [
            'description' => 'Summarize the conversation so far to free context window.',
            'takesArgs' => false,
        ],
    ];

    /**
     * @return array<string, array{description: string, takesArgs: bool}>
     */
    public static function availableSlashCommands(): array
    {
        return self::SLASH_COMMANDS;
    }

    /**
     * Peek at the most recent message on the session: if it's a user turn
     * whose text starts with "/", return the trimmed command. Returns null
     * for any other shape (assistant follows, no messages, non-command
     * text), in which case run() proceeds to call the LLM normally.
     */
    private function latestSlashCommand(string $sessionId): ?string
    {
        /** @var MessageRecord|null $latest */
        $latest = MessageRecord::find()
            ->where(['sessionId' => $sessionId])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if ($latest === null || $latest->role !== 'user') {
            return null;
        }

        try {
            /** @var list<array<string, mixed>> $blocks */
            $blocks = json_decode($latest->content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $text = trim($text);
        if (! str_starts_with($text, '/')) {
            return null;
        }

        return $text;
    }

    /**
     * Parse and execute a slash command. Writes feedback to the transcript
     * as an assistant turn so the chat surface renders something — the
     * user just typed a message and the natural UX is to see _some_
     * response, even when no provider call happened.
     *
     * Unknown commands aren't an error: they get a friendly assistant
     * reply that lists what's available. Errors during execution (e.g.
     * compaction with nothing to summarize) get persisted as an `error`
     * block on the assistant turn so the UI shows the red box.
     */
    private function dispatchSlashCommand(string $sessionId, string $rawCommand): void
    {
        $trimmed = ltrim($rawCommand, '/');
        $parts = preg_split('/\s+/', $trimmed, 2) ?: [''];
        $name = strtolower((string) ($parts[0] ?? ''));

        if (! isset(self::SLASH_COMMANDS[$name])) {
            $known = implode(', ', array_map(static fn (string $n): string => "/{$n}", array_keys(self::SLASH_COMMANDS)));
            $this->saveMessage($sessionId, 'assistant', [[
                'type' => 'text',
                'text' => "Unknown command `/{$name}`. Available: {$known}.",
            ]]);
            return;
        }

        switch ($name) {
            case 'compact':
                try {
                    // Force-set the recovery flag _before_ compact() so a
                    // mid-summarization context-length error from the small
                    // model can't cascade into a second compact attempt.
                    $this->alreadyCompactedThisTurn = true;
                    $this->compact($sessionId);
                    $session = SessionRecord::findOne(['id' => $sessionId]);
                    if ($session?->compactionPivotId === null) {
                        // compact() bails when there's no assistant message
                        // to cut at (e.g. very fresh session). Tell the user
                        // why so they don't think the command silently failed.
                        $this->saveMessage($sessionId, 'assistant', [[
                            'type' => 'text',
                            'text' => 'Nothing to compact yet — there needs to be at least one assistant reply in the conversation first.',
                        ]]);
                        return;
                    }
                    $this->saveMessage($sessionId, 'assistant', [[
                        'type' => 'text',
                        'text' => 'Conversation compacted. Earlier turns have been replaced with a summary so the context window has room to keep working.',
                    ]]);
                } catch (\Throwable $e) {
                    $this->saveMessage($sessionId, 'assistant', [[
                        'type' => 'error',
                        'text' => 'Could not compact the conversation: '.$e->getMessage(),
                    ]]);
                }
                return;
        }
    }

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
        // Reset the per-run compaction flag. AgentLoop is a singleton (see
        // Plugin::registerContainerBindings), so a flag left over from a
        // prior run would prevent recovery on the next session if both
        // sessions overflowed.
        $this->alreadyCompactedThisTurn = false;

        // Slash-command short-circuit: if the user's most recent message
        // starts with "/", treat it as a built-in action (compaction, etc.)
        // rather than a prompt to send to the LLM. Slash commands run
        // inside the same queue job because they may need DB writes that
        // benefit from the worker's longer TTR, and because the user wants
        // them to feel like normal turns in the transcript.
        $slashCommand = $this->latestSlashCommand($sessionId);
        if ($slashCommand !== null) {
            $this->dispatchSlashCommand($sessionId, $slashCommand);
            return;
        }

        // Pre-flight: if the last assistant turn consumed >= 95% of the
        // model's window, summarize before we even attempt this turn. The
        // load that follows then sees the post-compaction history. Setting
        // alreadyCompactedThisTurn here means a follow-up context-length
        // error in the same run won't trigger a second (redundant) compact —
        // we'd just bail to the user.
        if ($this->shouldCompact($sessionId)) {
            $this->compact($sessionId);
            $this->alreadyCompactedThisTurn = true;
        }

        $messages = $this->ensureToolResults($this->loadMessages($sessionId));
        $tools = $this->registry->descriptors(onlyAllowed: true);

        // Apply the session-scoped tool-mode filter (Full / Draft / Read-only
        // / Custom). Read once at the top of run() — the loop's iterations
        // re-use the same tool list rather than re-reading per turn, so a
        // mode change mid-run won't take effect until the next actionSend.
        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session !== null) {
            $tools = $this->registry->filterByToolMode(
                $tools,
                (string) ($session->toolMode ?? 'full'),
                $session->enabledTools,
            );
        }

        while (true) {
            if ($this->isStopRequested($sessionId)) {
                $this->recordStopMarker($sessionId);
                return;
            }

            $response = $this->callWithCompactionRecovery($sessionId, $messages, $tools);

            // The recovery path may have rewritten history with a fresh
            // summary, so reload the in-memory transcript from the DB before
            // appending the new assistant turn. Without this, the next
            // iteration would still be operating on the pre-compaction array.
            if ($response['compacted']) {
                $messages = $this->ensureToolResults($this->loadMessages($sessionId));
            }

            $providerResponse = $response['response'];

            $this->saveMessage(
                $sessionId,
                'assistant',
                $providerResponse->content,
                $providerResponse->raw,
                inputTokens: $providerResponse->inputTokens,
                outputTokens: $providerResponse->outputTokens,
            );
            $messages[] = ['role' => 'assistant', 'content' => $providerResponse->content];

            if ($providerResponse->stopReason !== 'tool_use') {
                break;
            }

            // Re-check between LLM response and tool execution. If the user
            // hit Stop while we were waiting on the model, short-circuit the
            // tools but still emit tool_result blocks so the saved transcript
            // matches every tool_use id — otherwise the next provider call
            // would reject the malformed conversation.
            $stopMidTurn = $this->isStopRequested($sessionId);

            $toolResults = [];
            foreach ($providerResponse->content as $block) {
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
                    // Prefer structured blocks (text + image) when the tool
                    // supplied them so vision-capable providers see the image
                    // bytes; fall back to the flat text payload otherwise.
                    'content' => $output->blocks ?? $output->text,
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
     * @param int|null $inputTokens Prompt tokens from the provider's usage payload.
     * @param int|null $outputTokens Completion tokens from the provider's usage payload.
     */
    private function saveMessage(
        string $sessionId,
        string $role,
        array $content,
        ?array $rawResponse = null,
        array $assetIds = [],
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): MessageRecord {
        // INVALID_UTF8_SUBSTITUTE is defense-in-depth: tools should return
        // valid UTF-8, but a single stray byte from any external source (a
        // fetched page, a tool that shells out, a provider's raw payload)
        // would otherwise abort the turn and leave the conversation with an
        // unanswered tool_use that the next provider call rejects. Replacing
        // bad bytes with U+FFFD keeps the loop moving; THROW_ON_ERROR still
        // catches the structural failures (recursion, NaN/INF) we care about.
        $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

        $record = new MessageRecord();
        $record->sessionId = $sessionId;
        $record->role = $role;
        $record->content = json_encode($content, $flags);
        $record->rawResponse = $rawResponse === null
            ? null
            : json_encode($rawResponse, $flags);
        $record->assetIds = $assetIds === []
            ? null
            : json_encode(array_map('intval', $assetIds), $flags);
        $record->inputTokens = $inputTokens;
        $record->outputTokens = $outputTokens;
        $record->save();

        return $record;
    }

    /**
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    private function loadMessages(string $sessionId): array
    {
        // When the session has a compaction pivot, every record with a lower
        // id was already folded into the summary — only load from the pivot
        // forward. The summary row itself has role='summary' and gets folded
        // into the next user turn the same way page-context system notes do.
        $session = SessionRecord::findOne(['id' => $sessionId]);
        $pivotId = $session?->compactionPivotId;

        $query = MessageRecord::find()
            ->where(['sessionId' => $sessionId])
            ->orderBy(['id' => SORT_ASC]);

        if ($pivotId !== null) {
            // Strict `>` (not `>=`): the pivot is the id of the last
            // *summarized* message, so it itself should be skipped. The
            // freshly-written summary row has an id higher than the pivot
            // and is therefore loaded.
            $query->andWhere(['>', 'id', (int) $pivotId]);
        }

        /** @var list<MessageRecord> $allRecords */
        $allRecords = $query->all();

        // Pull summary rows out of the main iteration so we can emit them
        // first regardless of their physical id ordering. The summary was
        // written *after* the trailing user message in actionSend, so its
        // id is highest — but logically it belongs at the start of the
        // visible history, before any of the trailing messages.
        $summaries = [];
        $records = [];
        foreach ($allRecords as $r) {
            if ($r->role === 'summary') {
                $summaries[] = $r;
            } else {
                $records[] = $r;
            }
        }

        $messages = [];
        // Buffer of pending system-context text blocks. Anthropic's messages
        // API only allows user/assistant in `messages[]`, and we can't move
        // them into the top-level `system` parameter mid-conversation without
        // losing their ordering relative to the user turns they describe. So
        // we fold the buffered system text into the next user turn — the
        // model reads it as part of the user's message, with a clear delimiter.
        $pendingSystem = [];

        // Seed pendingSystem with the active summary so the next user turn
        // gets the summary prepended as context. Multiple summary rows can
        // exist when the same conversation was compacted more than once;
        // we render them in id order so the LLM sees earliest-first.
        foreach ($summaries as $sum) {
            /** @var list<array<string, mixed>> $sumContent */
            $sumContent = json_decode($sum->content, true, 512, JSON_THROW_ON_ERROR);
            foreach ($sumContent as $block) {
                if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                    $pendingSystem[] = [
                        'type' => 'text',
                        'text' => "[Summary of the conversation so far]\n".$block['text'],
                    ];
                }
            }
        }

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
     * Decide whether the last persisted assistant turn pushed the conversation
     * past the configured compaction threshold. We use `inputTokens` (the
     * prompt the provider _received_) rather than total tokens because that's
     * a closer match for "what the next request will start with" — output
     * tokens get rolled into the next prompt as part of history, but inputs
     * tend to dominate well-before completions do.
     */
    private function shouldCompact(string $sessionId): bool
    {
        $contextWindow = $this->contextWindow();
        if ($contextWindow === null || $contextWindow <= 0) {
            return false;
        }

        /** @var MessageRecord|null $latest */
        $latest = MessageRecord::find()
            ->where(['sessionId' => $sessionId, 'role' => 'assistant'])
            ->andWhere(['not', ['inputTokens' => null]])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if ($latest === null) {
            return false;
        }

        $used = (int) ($latest->inputTokens ?? 0) + (int) ($latest->outputTokens ?? 0);

        return $used >= (int) floor($contextWindow * self::COMPACTION_THRESHOLD);
    }

    /**
     * Resolve the model's context window from plugin config. Returns null
     * when the host hasn't configured one — in that case both the pre-flight
     * check and the UI gauge stay dormant, but the error-recovery path can
     * still kick in if the provider rejects the request.
     */
    private function contextWindow(): ?int
    {
        if ($this->contextWindowOverride !== null) {
            return $this->contextWindowOverride > 0 ? $this->contextWindowOverride : null;
        }

        try {
            $settings = Plugin::getInstance()->getSettingsArray();
        } catch (\Throwable) {
            return null;
        }

        $value = $settings['contextWindow'] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * Wrap a single provider call with auto-compaction. Two recovery paths:
     *
     *   1. Pre-flight already ran in run(); if it triggered, $messages
     *      already reflects the compacted history.
     *   2. The provider returns a context-length 400 anyway (the threshold
     *      isn't exact and tool outputs can blow past it mid-turn). We
     *      compact, reload, and retry once.
     *
     * Returns the response plus a flag telling the caller whether a
     * compaction happened so it can refresh its in-memory message array.
     *
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     * @param list<\markhuot\craftai\tools\ToolDescriptor> $tools
     * @return array{response: \markhuot\craftai\agent\providers\ProviderResponse, compacted: bool}
     */
    private function callWithCompactionRecovery(string $sessionId, array $messages, array $tools): array
    {
        try {
            return [
                'response' => $this->provider->createMessage($messages, $tools),
                'compacted' => false,
            ];
        } catch (\Throwable $e) {
            if (! $this->isContextLengthError($e)) {
                throw $e;
            }

            // Don't loop forever: if we've _already_ compacted this run and
            // still get a context-length error, the summary itself is too
            // big (or the trailing tool output is). Let the exception bubble
            // up to AgentJob which renders an error message to the user.
            if ($this->alreadyCompactedThisTurn) {
                throw $e;
            }

            $this->alreadyCompactedThisTurn = true;
            $this->compact($sessionId);
            $compactedMessages = $this->ensureToolResults($this->loadMessages($sessionId));

            return [
                'response' => $this->provider->createMessage($compactedMessages, $tools),
                'compacted' => true,
            ];
        }
    }

    /**
     * Recognize a "you exceeded the context window" error so we can recover
     * instead of failing the job. Different providers phrase this differently,
     * so we check the HTTP status (400) and look for a few canonical fragments
     * in the body. False positives are cheap (we'd just summarize unnecessarily);
     * false negatives bubble up as job failures.
     */
    private function isContextLengthError(\Throwable $e): bool
    {
        if (! $e instanceof \GuzzleHttp\Exception\ClientException) {
            return false;
        }

        $response = $e->getResponse();
        if ($response === null || $response->getStatusCode() !== 400) {
            return false;
        }

        $body = strtolower((string) $response->getBody());
        $needles = [
            'context length',
            'maximum context',
            'context_length_exceeded',
            'prompt is too long',
            'too many tokens',
            'context window',
        ];

        foreach ($needles as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Guards the recovery path from looping when the summarized prompt still trips the limit. */
    private bool $alreadyCompactedThisTurn = false;

    /**
     * Replace the session's prior history with a single summary row.
     * Summarizes everything up to and including the most recent assistant
     * turn; any trailing user/system rows survive into the next request
     * so an in-flight question (or page-context note) isn't lost.
     *
     * The pivot column on the session points at the id of the last
     * summarized message. loadMessages() then skips every record with a
     * lower-or-equal id and folds the summary text (a freshly-written
     * role='summary' row, which has an id > pivot) in as a system note.
     *
     * The summary is generated by the small-model provider (or whichever
     * provider was configured via setSmallProvider() in tests).
     */
    private function compact(string $sessionId): void
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session === null) {
            return;
        }

        $pivotId = $session->compactionPivotId;

        $query = MessageRecord::find()
            ->where(['sessionId' => $sessionId])
            ->orderBy(['id' => SORT_ASC]);
        if ($pivotId !== null) {
            $query->andWhere(['>', 'id', (int) $pivotId]);
        }
        // Don't roll older summary rows back into the new summary — once a
        // summary becomes pre-pivot it's already been incorporated into
        // the conversation the next summarizer pass sees as "TRANSCRIPT".
        $query->andWhere(['!=', 'role', 'summary']);

        /** @var list<MessageRecord> $records */
        $records = $query->all();
        if (count($records) === 0) {
            return;
        }

        // Find the index of the last assistant message in records — that's
        // the cutoff. Everything from the start up to and including it gets
        // summarized; trailing user/system rows (typically the question
        // that just arrived from actionSend) survive into the next request.
        $cutoff = null;
        foreach ($records as $i => $r) {
            if ($r->role === 'assistant') {
                $cutoff = $i;
            }
        }

        if ($cutoff === null) {
            // Nothing useful to summarize — the only post-pivot rows are
            // user/system inputs the assistant hasn't responded to yet.
            // Bail without writing a summary so we don't re-summarize
            // unanswered questions on every run.
            return;
        }

        // Don't split a tool_use/tool_result pair. The cutoff assistant may
        // have issued tool_use blocks whose matching tool_result is the very
        // next user turn — leaving that user turn on the post-summary side
        // would produce an orphan tool_result on the next provider call, and
        // strict providers (DeepSeek) reject it with "Messages with role
        // 'tool' must be a response to a preceding message". Advance the
        // cutoff past any immediately-following user turns that carry
        // tool_result blocks so the pair stays together inside the summary.
        $total = count($records);
        while (
            $cutoff + 1 < $total
            && $records[$cutoff + 1]->role === 'user'
            && $this->messageContainsToolResult($records[$cutoff + 1])
        ) {
            $cutoff++;
        }

        $toSummarize = array_slice($records, 0, $cutoff + 1);

        $transcript = $this->renderTranscriptForSummary($toSummarize);
        $summaryText = $this->callSummarizer($transcript);

        if ($summaryText === '') {
            // Don't leave the session in a half-compacted state if the
            // summarizer failed — better to error out cleanly so the user can
            // retry than to silently drop history.
            throw new \RuntimeException('craft-ai: summarization returned empty content; refusing to compact.');
        }

        $this->saveMessage($sessionId, 'summary', [[
            'type' => 'text',
            'text' => $summaryText,
        ]]);

        // Pivot = id of the last summarized row. loadMessages() filters with
        // ['>', 'id', $pivot], so the pivot row itself is excluded but the
        // freshly-written summary row (with a higher id) is included.
        $session->compactionPivotId = (int) $toSummarize[count($toSummarize) - 1]->id;
        $session->save();
    }

    private function messageContainsToolResult(MessageRecord $record): bool
    {
        try {
            $blocks = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }
        if (! is_array($blocks)) {
            return false;
        }
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                return true;
            }
        }
        return false;
    }

    /**
     * Squash the saved transcript into a plain-text view the summarizer can
     * read. We deliberately flatten tool_use/tool_result blocks to short
     * descriptions — the summarizer doesn't need the full tool outputs and
     * keeping them would just push us closer to the same context wall we're
     * trying to escape.
     *
     * @param list<MessageRecord> $records
     */
    private function renderTranscriptForSummary(array $records): string
    {
        $lines = [];

        foreach ($records as $record) {
            try {
                /** @var list<array<string, mixed>> $blocks */
                $blocks = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            $rendered = $this->renderBlocksForSummary($blocks);
            if ($rendered === '') {
                continue;
            }

            $lines[] = strtoupper($record->role).":\n".$rendered;
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function renderBlocksForSummary(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
                continue;
            }
            if ($type === 'thinking' && is_string($block['thinking'] ?? null)) {
                $parts[] = '[thinking] '.$block['thinking'];
                continue;
            }
            if ($type === 'tool_use') {
                $name = is_string($block['name'] ?? null) ? $block['name'] : 'tool';
                $parts[] = "[tool_use:{$name}]";
                continue;
            }
            if ($type === 'tool_result') {
                $content = $block['content'] ?? '';
                $text = is_string($content)
                    ? $content
                    : (is_array($content) ? $this->renderBlocksForSummary($content) : '');
                // Cap each tool result at ~1KB so a single huge fetch_webpage
                // payload doesn't dominate the summary input.
                if (strlen($text) > 1000) {
                    $text = substr($text, 0, 1000).'… [truncated]';
                }
                $parts[] = '[tool_result] '.$text;
                continue;
            }
            if ($type === 'error' && is_string($block['text'] ?? null)) {
                $parts[] = '[error] '.$block['text'];
                continue;
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * Call the configured small-model provider to produce a summary string.
     * Falls back to the main provider when no small model is wired up.
     */
    private function callSummarizer(string $transcript): string
    {
        $provider = $this->smallProviderOverride;
        if ($provider === null) {
            try {
                $provider = Plugin::getInstance()->getSmallModelProvider();
            } catch (\Throwable) {
                $provider = $this->provider;
            }
        }

        $prompt = <<<PROMPT
Summarize the following conversation between a user and an AI agent. The
summary will replace the prior transcript in the conversation's context
window, so it must preserve every fact, decision, open question, and any
state the agent needs to continue helping the user.

Write the summary as bullet-pointed prose. Keep it dense and specific —
include entity IDs, file paths, URLs, error messages, and tool outcomes
verbatim where they matter. Do not address the user directly; this text
is an internal context note for the agent.

TRANSCRIPT:
{$transcript}
PROMPT;

        $response = $provider->createMessage([[
            'role' => 'user',
            'content' => [['type' => 'text', 'text' => $prompt]],
        ]]);

        $text = '';
        foreach ($response->content as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return trim($text);
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
