<?php

namespace markhuot\craftai\agent;

use craft\elements\Asset;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\Plugin;
use markhuot\craftai\records\CommentRecord;
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
     * Built-in system prompt sent on every provider call. Captures behavioural
     * guidance the agent should follow regardless of how a host project
     * customises its own `'system'` setting. Any user-configured prompt is
     * appended after this with a blank-line separator.
     *
     * The "pause and ask" guidance below was added after a session where the
     * agent watched a preview render wrong, decided the template was at fault,
     * rewrote it with invalid Twig, 500'd the site, and then pivoted to a
     * legacy workaround — all without checking in. Encoding this rule into
     * the system prompt is the lightest-weight place to discourage that loop.
     */
    private const BUILT_IN_SYSTEM = <<<'PROMPT'
When a change you just made appears to render or behave incorrectly downstream — content that does not appear in the preview, a template that errors, output that does not match what you intended — pause and surface the observation to the user before making further edits. Describe what you see, what you think is causing it, and what you would change next. Wait for permission or correction.

This is guidance, not a hard rule. With explicit permission, or when you have a clear reason (a typo you just introduced, an obvious field omission), you can proceed. The failure mode to avoid is silently chasing perceived issues by editing templates to match content, deleting and recreating entries, or pivoting to workarounds — that has historically erased correct work and introduced new bugs.

Reply to the user in the language they wrote to you in, regardless of what language the content you're working with is in. The entries, drafts, fields, and translations you read or write may be in Spanish, French, Japanese, etc.; your status updates, questions, and final summary stay in the editor's language. If the user's first message is in English, stay in English even when filling a Spanish field or producing a French translation.
PROMPT;

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
        'review' => [
            'description' => 'Review an entry or draft and leave inline comments. Usage: /review entry:123 or /review draft:456.',
            'takesArgs' => true,
        ],
    ];

    /**
     * Merge built-in commands with user-defined commands from plugin
     * settings. Built-ins win on name collision so an editor can't shadow
     * `/compact` or `/review` (the {@see \markhuot\craftai\models\Command}
     * validator also rejects those names, but we re-enforce the priority
     * here so a hand-edited project-config file can't smuggle one
     * through). Disabled user commands are filtered out.
     *
     * @return array<string, array{description: string, takesArgs: bool}>
     */
    public static function availableSlashCommands(): array
    {
        $commands = self::SLASH_COMMANDS;
        foreach (self::userSlashCommands() as $name => $meta) {
            if (isset($commands[$name])) {
                continue;
            }
            $commands[$name] = $meta;
        }
        return $commands;
    }

    /**
     * Load the user-defined slash commands from plugin settings. Returns
     * an empty list when the plugin/settings aren't available (e.g. during
     * a console boot before the plugin has been fully resolved) so callers
     * can rely on the built-ins always being there.
     *
     * @return array<string, array{description: string, takesArgs: bool}>
     */
    private static function userSlashCommands(): array
    {
        try {
            $settings = Plugin::getInstance()->getSettings();
        } catch (\Throwable) {
            return [];
        }

        if (! $settings instanceof \markhuot\craftai\models\Settings) {
            return [];
        }

        $out = [];
        foreach ($settings->getCommands() as $cmd) {
            if (! $cmd->enabled) {
                continue;
            }
            if ($cmd->name === '') {
                continue;
            }
            // Truncate the prompt for the autocomplete blurb so the menu
            // stays scannable. The full prompt still drives execution.
            $description = trim(preg_replace('/\s+/', ' ', $cmd->prompt) ?? $cmd->prompt);
            if (mb_strlen($description) > 120) {
                $description = mb_substr($description, 0, 117).'…';
            }
            $out[$cmd->name] = [
                'description' => $description,
                'takesArgs' => true,
            ];
        }
        return $out;
    }

    /**
     * Look up a single user-defined command by name. Returns null when
     * the command isn't configured or is disabled — callers route to the
     * "unknown command" reply in that case.
     */
    private static function findUserCommand(string $name): ?\markhuot\craftai\models\Command
    {
        try {
            $settings = Plugin::getInstance()->getSettings();
        } catch (\Throwable) {
            return null;
        }

        if (! $settings instanceof \markhuot\craftai\models\Settings) {
            return null;
        }

        foreach ($settings->getCommands() as $cmd) {
            if ($cmd->name === $name && $cmd->enabled) {
                return $cmd;
            }
        }
        return null;
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
     *
     * Returns true when the command is terminal (run() should bail without
     * calling the LLM), false when the command rewrote conversation state
     * and run() should proceed to the LLM with the new state.
     */
    private function dispatchSlashCommand(string $sessionId, string $rawCommand): bool
    {
        $trimmed = ltrim($rawCommand, '/');
        $parts = preg_split('/\s+/', $trimmed, 2) ?: [''];
        $name = strtolower((string) ($parts[0] ?? ''));
        $args = isset($parts[1]) ? trim((string) $parts[1]) : '';

        if (! isset(self::SLASH_COMMANDS[$name])) {
            // Fall through to the user-defined command catalog. Anything
            // not in the built-ins and not in the user catalog gets the
            // "unknown" reply below — same behavior as before, but the
            // user-defined commands now sit between the two.
            $userCmd = self::findUserCommand($name);
            if ($userCmd !== null) {
                return $this->dispatchUserCommand($sessionId, $userCmd, $args);
            }

            $known = implode(', ', array_map(
                static fn (string $n): string => "/{$n}",
                array_keys(self::availableSlashCommands()),
            ));
            $this->saveMessage($sessionId, 'assistant', [[
                'type' => 'text',
                'text' => "Unknown command `/{$name}`. Available: {$known}.",
            ]]);
            return true;
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
                        return true;
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
                return true;

            case 'review':
                return $this->dispatchReview($sessionId, $args);
        }
    }

    /**
     * Set up a review session: parse the user's `/review` argument, rewrite
     * the trailing user message into a detailed review prompt with the
     * target element baked in, and inject a system note with review
     * etiquette. Returns false so {@see run} falls through to the LLM with
     * the rewritten state — the agent then drives the review autonomously,
     * calling `get_entry`/`get_draft` and `leave_comment` as needed.
     *
     * If the argument is missing or unparseable, writes a friendly
     * assistant reply asking for an explicit target and returns true to
     * stop. We deliberately don't auto-pick the "most recent entry" from
     * conversation context — the cost of reviewing the wrong element is
     * higher than the cost of one extra user turn.
     */
    private function dispatchReview(string $sessionId, string $args): bool
    {
        $target = self::parseReviewTarget($args);

        if ($target === null) {
            $this->saveMessage($sessionId, 'assistant', [[
                'type' => 'text',
                'text' => "I need an explicit target to review. Try `/review entry:123` for a canonical entry or `/review draft:456` for a draft. (A bare numeric ID like `/review 123` also works and is treated as an entry.)",
            ]]);
            return true;
        }

        [$kind, $id] = $target;
        $idArg = $kind === 'draft' ? "draftId: {$id}" : "entryId: {$id}";
        $reviewLabel = $kind === 'draft' ? "draft #{$id}" : "entry #{$id}";
        $fetchTool = $kind === 'draft' ? 'get_draft' : 'get_entry';

        // Rewrite the user's "/review …" text into the verbose review
        // prompt so the LLM sees a normal instruction rather than the slash
        // command. This is the simplest way to keep the rest of the loop
        // (compaction, history loading, tool execution) oblivious to the
        // command-vs-prompt distinction — by the time loadMessages() runs
        // there's no `/review` left in the transcript.
        $this->rewriteLatestUserMessage(
            $sessionId,
            <<<PROMPT
            Please conduct a thorough editorial review of {$reviewLabel}.

            1. Read the element with `{$fetchTool}` (using {$idArg}) to see
               its current contents and field structure.
            2. Evaluate each populated field on its own merits — clarity,
               structure, tone, factual accuracy, missing context, broken
               or unclear references, accessibility (alt text, headings),
               SEO basics where relevant.
            3. For every issue you find, call `leave_comment` with the
               appropriate `fieldHandle` so the indicator surfaces next to
               that field in the CP. Pair `{$idArg}` on the call. Use a
               top-level note (omit fieldHandle) only for issues that span
               the whole entry. Keep comment bodies specific and
               actionable — they will be read verbatim by the editor.
               When commenting on a field _inside_ a Matrix block, target
               the block itself: Matrix blocks are entries in Craft 5, so
               the keys nested under each Matrix field in the
               `{$fetchTool}` response (e.g. `"192"`, `"193"`) are valid
               `entryId` values. Pass the block's id as `entryId` and the
               inner field handle (e.g. `blogHeadingText`) as
               `fieldHandle` — don't reuse the outer Matrix field handle
               for inner-block feedback.
            4. Don't comment on fields that look good. A short review is
               fine; quality over quantity.
            5. When you're done, summarize what you flagged in a few
               sentences (not as another comment — as your normal reply).
            PROMPT,
        );

        $this->appendSystemContext(
            $sessionId,
            <<<NOTE
            [Review session started]
            Target: {$reviewLabel}
            Use leave_comment / resolve_comment / get_comments to manage
            inline feedback. The user can reply to comments from the entry
            edit screen — their replies arrive as normal user turns in
            this chat. They can also mark comments resolved themselves;
            check get_comments(status: "open") before claiming the review
            is complete.
            NOTE,
        );

        return false;
    }

    /**
     * Expand a user-defined slash command into its configured prompt and
     * fall through to the LLM. Mirrors {@see dispatchReview}: the literal
     * `/name …` text gets replaced with the persisted prompt so the rest
     * of the loop (compaction, history loading, tool execution) doesn't
     * need to know slash commands exist.
     *
     * Argument handling:
     *   - If the prompt contains a `{args}` placeholder, it's replaced
     *     with whatever the user typed after the command name (empty
     *     string when nothing followed).
     *   - Otherwise non-empty args are appended on a separate line as
     *     "Additional input: …" so the agent still sees the intent.
     */
    private function dispatchUserCommand(string $sessionId, \markhuot\craftai\models\Command $cmd, string $args): bool
    {
        $prompt = $cmd->prompt;

        if (str_contains($prompt, '{args}')) {
            $prompt = str_replace('{args}', $args, $prompt);
        } elseif ($args !== '') {
            $prompt .= "\n\nAdditional input: ".$args;
        }

        $this->rewriteLatestUserMessage($sessionId, $prompt);
        return false;
    }

    /**
     * Rewrite the trailing user message's text content so the agent sees
     * an expanded prompt instead of the literal slash command. Only
     * touches the first `text` block — attachment blocks (assetIds column)
     * survive untouched. If there's no user message at all (impossible in
     * practice — dispatch only runs when latestSlashCommand returned a
     * value) we no-op.
     */
    private function rewriteLatestUserMessage(string $sessionId, string $replacement): void
    {
        /** @var MessageRecord|null $latest */
        $latest = MessageRecord::find()
            ->where(['sessionId' => $sessionId, 'role' => 'user'])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if ($latest === null) {
            return;
        }

        try {
            /** @var list<array<string, mixed>> $blocks */
            $blocks = json_decode($latest->content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return;
        }

        $rewroteOne = false;
        foreach ($blocks as $i => $block) {
            if (($block['type'] ?? '') === 'text') {
                $blocks[$i]['text'] = $replacement;
                $rewroteOne = true;
                break;
            }
        }

        if (! $rewroteOne) {
            $blocks[] = ['type' => 'text', 'text' => $replacement];
        }

        $latest->content = json_encode(
            $blocks,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $latest->save();
    }

    /**
     * Parse the argument tail of `/review …` into a (kind, id) tuple.
     * Accepts:
     *   - `entry:123`  → ['entry', 123]
     *   - `draft:456`  → ['draft', 456]
     *   - `123`        → ['entry', 123]  (bare numeric ID is canonical entry)
     *   - `#123`       → ['entry', 123]  (CP-style ref)
     *
     * Anything else returns null so the dispatcher can prompt for clarity.
     *
     * @return array{0: 'entry'|'draft', 1: int}|null
     */
    private static function parseReviewTarget(string $args): ?array
    {
        $args = trim($args);
        if ($args === '') {
            return null;
        }

        if (preg_match('/^(entry|draft)\s*[:#=]\s*(\d+)$/i', $args, $m)) {
            return [strtolower($m[1]) === 'draft' ? 'draft' : 'entry', (int) $m[2]];
        }

        if (preg_match('/^#?(\d+)$/', $args, $m)) {
            return ['entry', (int) $m[1]];
        }

        return null;
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

    /**
     * Branch a session into a new child session that contains a copy of the
     * parent's history up to and including `throughMessageId`. Used to give
     * each review comment its own conversation surface: when the user opens
     * a comment to discuss it, we fork at the assistant turn that left the
     * comment so the resulting back-and-forth is isolated from the main
     * agent run (and from any sibling comment threads).
     *
     * Notes on the copy:
     *  - Messages are append-only, so the copy is a straight insert of new
     *    rows pointing at the fork's session id. The original rows are left
     *    untouched.
     *  - We deliberately skip `compactionPivotId` on the fork. The pivot is
     *    a message id from the parent transcript; even if we tried to remap
     *    it, the fork has its own clean slate and the next compaction will
     *    set a fresh pivot if needed.
     *  - Token usage columns are copied verbatim. The fork inherits the
     *    parent's spend so the context gauge stays consistent, but no
     *    further charges accrue until the fork actually runs.
     *  - Per-message `assetIds` are preserved so attached assets stay
     *    accessible from inside the fork.
     */
    public function forkSession(string $parentSessionId, int $throughMessageId, int $originatingCommentId): ?string
    {
        $parent = SessionRecord::findOne(['id' => $parentSessionId]);
        if ($parent === null) {
            return null;
        }

        $forkId = \craft\helpers\StringHelper::UUID();

        $fork = new SessionRecord();
        $fork->id = $forkId;
        $fork->active = false;
        $fork->stopRequested = false;
        // Derive the fork's title from the comment that triggered it
        // rather than copying the parent's. The whole point of a fork
        // is that it's a focused discussion of one comment — so the
        // sidebar label should reflect the comment, not the parent
        // run. Falls back to the parent title if the comment lookup
        // fails (e.g. mid-deletion races).
        $fork->title = self::forkTitleFromComment($originatingCommentId) ?? $parent->title;
        $fork->userId = $parent->userId;
        $fork->toolMode = $parent->toolMode;
        $fork->enabledTools = $parent->enabledTools;
        $fork->clientType = $parent->clientType;
        $fork->parentSessionId = $parentSessionId;
        $fork->originatingCommentId = $originatingCommentId;
        $fork->save();

        /** @var list<MessageRecord> $rows */
        $rows = MessageRecord::find()
            ->where(['sessionId' => $parentSessionId])
            ->andWhere(['<=', 'id', $throughMessageId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        // If the last copied assistant turn has tool_use blocks, the
        // matching tool_results live on the immediately following user
        // turn — pull that in too so the fork doesn't start its life
        // with an orphan tool_use (the next provider call would reject
        // it). We chase forward as long as the trailing assistant turn
        // is still emitting tool_use blocks: a single agent turn can
        // emit N tool_uses across N round-trips before settling on
        // text, and we want the fork to inherit the whole settled
        // round.
        $rows = $this->extendFork($parentSessionId, $rows);

        $lastCopiedId = null;
        foreach ($rows as $row) {
            $copy = new MessageRecord();
            $copy->sessionId = $forkId;
            $copy->role = $row->role;
            $copy->content = $row->content;
            $copy->rawResponse = $row->rawResponse;
            $copy->assetIds = $row->assetIds;
            $copy->inputTokens = $row->inputTokens;
            $copy->outputTokens = $row->outputTokens;
            $copy->save();
            $lastCopiedId = (int) $copy->id;
        }

        // Remember the boundary so the popover can show a precise reply
        // count without diffing against the parent. Sessions never copy
        // again, so this pointer is set once and stable for the life of
        // the fork.
        if ($lastCopiedId !== null) {
            $fork->forkPivotMessageId = $lastCopiedId;
            $fork->save();
        }

        return $forkId;
    }

    /**
     * Derive a fork-session title from its originating comment so the
     * sidebar can distinguish it from the parent at a glance. The body
     * is already a short human-written description of the feedback, so
     * we use it verbatim (truncated) rather than calling the LLM —
     * cheaper, faster, and predictable.
     *
     * Returns null when the comment isn't found or has no body to work
     * with; callers fall back to the parent's title in that case.
     */
    private static function forkTitleFromComment(int $originatingCommentId): ?string
    {
        if ($originatingCommentId <= 0) {
            return null;
        }

        try {
            $comment = CommentRecord::findOne(['id' => $originatingCommentId]);
        } catch (\Throwable) {
            return null;
        }

        if ($comment === null) {
            return null;
        }

        $body = trim((string) ($comment->body ?? ''));
        // Strip newlines so a multi-paragraph comment doesn't break the
        // sidebar row. Collapse runs of whitespace to a single space.
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));

        if ($body === '') {
            // No body — derive something from the scope instead so the
            // sidebar isn't blank. Field comments are far more common
            // than entry-level notes, so the field handle path comes
            // first.
            $field = is_string($comment->fieldHandle ?? null) ? trim((string) $comment->fieldHandle) : '';
            return $field !== ''
                ? "Re: comment on {$field}"
                : 'Re: comment';
        }

        // Compact label for the sidebar. 64 chars leaves room for the
        // "Re: " prefix without truncating mid-word for most comments.
        $maxBodyLen = 60;
        if (mb_strlen($body) > $maxBodyLen) {
            $body = mb_substr($body, 0, $maxBodyLen - 1).'…';
        }

        return "Re: {$body}";
    }

    /**
     * Walk forward from the last copied row to capture any trailing
     * tool_result rows whose tool_use was already pulled in. Without
     * this, a fork that ends mid-round-trip (e.g. the assistant turn
     * that called leave_comment, but not the user turn carrying the
     * tool_result) would present the next provider call with an orphan
     * tool_use and either trip the API's validation or get a synthetic
     * error injected by {@see ensureToolResults()}.
     *
     * @param list<MessageRecord> $rows
     * @return list<MessageRecord>
     */
    private function extendFork(string $parentSessionId, array $rows): array
    {
        while (true) {
            $last = end($rows);
            if (! $last instanceof MessageRecord || $last->role !== 'assistant') {
                break;
            }

            $orphanIds = $this->unmatchedToolUseIds($last);
            if ($orphanIds === []) {
                break;
            }

            /** @var MessageRecord|null $next */
            $next = MessageRecord::find()
                ->where(['sessionId' => $parentSessionId])
                ->andWhere(['>', 'id', (int) $last->id])
                ->orderBy(['id' => SORT_ASC])
                ->one();

            if ($next === null || $next->role !== 'user') {
                break;
            }

            $rows[] = $next;

            // The user message we just pulled in may have triggered
            // *another* assistant turn with its own tool_use chain (a
            // multi-tool round-trip). Pull that in too if it exists —
            // we want to land on a clean text-only assistant turn or
            // the end of the conversation.
            /** @var MessageRecord|null $afterNext */
            $afterNext = MessageRecord::find()
                ->where(['sessionId' => $parentSessionId])
                ->andWhere(['>', 'id', (int) $next->id])
                ->orderBy(['id' => SORT_ASC])
                ->one();
            if ($afterNext instanceof MessageRecord && $afterNext->role === 'assistant') {
                $rows[] = $afterNext;
                continue;
            }

            break;
        }

        return $rows;
    }

    /**
     * Returns the tool_use ids in the given assistant row that don't
     * already have a matching tool_result somewhere earlier in the
     * conversation. In practice this is the simple case where the row
     * is the LAST one we've copied — but coding it as a general check
     * (rather than "assume orphans") keeps the helper reusable.
     */
    private function unmatchedToolUseIds(MessageRecord $row): array
    {
        $content = json_decode((string) $row->content, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($content)) {
            return [];
        }
        $ids = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && is_string($block['id'] ?? null)) {
                $ids[$block['id']] = true;
            }
        }
        return array_keys($ids);
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
            // Terminal commands (compact, unknown) return true and stop the
            // turn. Pass-through commands (review) return false after
            // rewriting state so we fall through to the LLM with the new
            // history loaded below.
            if ($this->dispatchSlashCommand($sessionId, $slashCommand)) {
                return;
            }
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
        $system = $this->composeSystemPrompt();

        // Apply the session-scoped tool-mode filter (Full / Draft / Read-only
        // / Custom). Read once at the top of run() — the loop's iterations
        // re-use the same tool list rather than re-reading per turn, so a
        // mode change mid-run won't take effect until the next actionSend.
        $session = SessionRecord::findOne(['id' => $sessionId]);
        $sessionClient = self::resolveSessionClient($session);
        if ($session !== null) {
            $tools = $this->registry->filterByToolMode(
                $tools,
                (string) ($session->toolMode ?? 'full'),
                $session->enabledTools,
            );
        }
        // Per-client filter: drop tools whose ALLOWED_CLIENTS doesn't
        // include the surface this session was minted from. Runs after the
        // user's tool-mode filter so a "Custom" allowlist that names a
        // surface-restricted tool still gets the surface filter applied.
        $tools = $this->registry->filterByClient($tools, $sessionClient);

        while (true) {
            if ($this->isStopRequested($sessionId)) {
                $this->recordStopMarker($sessionId);
                return;
            }

            $response = $this->callWithCompactionRecovery($sessionId, $messages, $tools, $system);

            // The recovery path may have rewritten history with a fresh
            // summary, so reload the in-memory transcript from the DB before
            // appending the new assistant turn. Without this, the next
            // iteration would still be operating on the pre-compaction array.
            if ($response['compacted']) {
                $messages = $this->ensureToolResults($this->loadMessages($sessionId));
            }

            $providerResponse = $response['response'];

            $assistantRecord = $this->saveMessage(
                $sessionId,
                'assistant',
                $providerResponse->content,
                $providerResponse->raw,
                inputTokens: $providerResponse->inputTokens,
                outputTokens: $providerResponse->outputTokens,
            );
            $assistantMessageId = (int) $assistantRecord->id;
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

                // Push the session's originating surface — not a hardcoded
                // ClientType::CP — so tools that gate on `getClient()` see
                // the same context the LLM saw when picking from the
                // surface-filtered tool list above.
                $this->toolContext->begin($sessionId, $toolUseId, $sessionClient ?? ClientType::CP, $assistantMessageId);
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

    /**
     * Decode the session's stored client surface back into a {@see ClientType}.
     * Sessions created before the `clientType` column existed fall back to
     * CP (the column default), and a bad value (e.g. a typo from a future
     * client we haven't taught the enum about yet) also falls back rather
     * than throwing — the agent loop is already running by the time we get
     * here and a runtime error would lose the user's turn.
     */
    private static function resolveSessionClient(?SessionRecord $session): ?ClientType
    {
        if ($session === null) {
            return null;
        }
        $raw = (string) ($session->clientType ?? 'cp');

        return ClientType::tryFrom($raw) ?? ClientType::CP;
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
    private function callWithCompactionRecovery(string $sessionId, array $messages, array $tools, ?string $system): array
    {
        try {
            return [
                'response' => $this->provider->createMessage($messages, $tools, $system),
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
                'response' => $this->provider->createMessage($compactedMessages, $tools, $system),
                'compacted' => true,
            ];
        }
    }

    /**
     * Combine the built-in behavioural prompt with any project-level `system`
     * setting so callers always get a single string (or null) to hand to the
     * provider. The project prompt comes after the built-in so a host can
     * layer additional context on top without losing the baked-in guidance.
     */
    private function composeSystemPrompt(): string
    {
        try {
            $settings = Plugin::getInstance()->getSettingsArray();
        } catch (\Throwable) {
            $settings = [];
        }

        $configured = $settings['system'] ?? null;
        $configured = is_string($configured) ? trim($configured) : '';

        if ($configured === '') {
            return self::BUILT_IN_SYSTEM;
        }

        return self::BUILT_IN_SYSTEM."\n\n".$configured;
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
