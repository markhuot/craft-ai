<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * @property string $id UUID identifying the session
 * @property bool $active Whether an agent loop is currently running
 * @property bool $stopRequested User asked the running loop to halt at its next safe checkpoint
 * @property string|null $title Short summary of the user's first question
 * @property int|null $userId Craft user that initiated the session
 * @property string $toolMode One of 'full', 'draft', 'readonly', 'custom' — controls which tools the agent sees this session
 * @property string|null $enabledTools JSON-encoded list<string> of tool names (only meaningful when toolMode = 'custom')
 * @property string $clientType Surface this session was created from ('cp', 'widget', 'mcp', 'code-component-field'); used to filter tools by their declared `ALLOWED_CLIENTS`
 * @property int|null $compactionPivotId MessageRecord id of the latest summary row; messages before it are skipped when loading
 * @property string|null $parentSessionId When this session was forked from another (today only used for comment threads), the parent's session id
 * @property int|null $originatingCommentId CommentRecord id that triggered the fork, when this session is a comment-thread fork
 * @property int|null $forkPivotMessageId Id of the last copied message on a fork session; messages with a higher id belong to the comment discussion, not the parent transcript
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftai_sessions}}';
    }

    /**
     * Persist one message row onto a session's transcript and return it.
     *
     * Static and keyed by `$sessionId` (rather than an instance method) so the
     * agent loop — which works in session-id strings and writes on a hot,
     * per-turn path — can record messages without first loading the record.
     *
     * `$content` is the canonical home for *encoding* messages, not for shaping
     * them: callers own the LLM content-block structure (text / tool_use /
     * tool_result / error / summary) and hand it in pre-built. This method only
     * JSON-encodes the columns and saves.
     *
     * @param  array<int, array<string, mixed>>  $content  Pre-shaped content blocks.
     * @param  array<string, mixed>|null  $rawResponse  Full provider payload (assistant turns only).
     * @param  list<int>  $assetIds  Craft asset IDs attached to this message.
     */
    public static function pushMessage(
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
     * Append a synthesized `system` note to this session's transcript.
     *
     * The widget sends a fresh page-context payload only when the user has
     * navigated since their last message; callers render that payload to prose
     * and append it here so the user can see "what the agent knows" and the
     * agent has stable context for the user turns that follow. Empty or
     * whitespace-only notes are skipped.
     */
    public function addSystemNote(string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }
        static::pushMessage((string) $this->id, 'system', [['type' => 'text', 'text' => $note]]);
    }

    /**
     * Append a `user` message to this session's transcript.
     *
     * @param  list<int>  $assetIds  Optional asset IDs the user attached to the
     *                               message. Stored alongside the message and
     *                               surfaced to the LLM as a text annotation so
     *                               the agent can request the asset's contents
     *                               through tools if needed.
     */
    public function addUserMessage(string $userMessage, array $assetIds = []): void
    {
        static::pushMessage((string) $this->id, 'user', [['type' => 'text', 'text' => $userMessage]], assetIds: $assetIds);
    }
}
