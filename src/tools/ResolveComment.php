<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\helpers\Db;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Comment as CommentBinder;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\validators\ExistingComment;

/**
 * Mark a review comment as resolved.
 *
 * Use this once the feedback you flagged with `leave_comment` has been
 * addressed — typically after you've made the fix yourself via
 * `upsert_draft`/`upsert_entry`, or after the user has confirmed the
 * change. Resolved comments stay in the database for traceability but
 * disappear from the entry edit screen's open-comment indicators.
 *
 * Resolving a comment that's already resolved is a no-op (returns
 * successfully without changing anything).
 */
class ResolveComment extends Tool
{
    public const KIND = ToolKind::DraftWrite;

    /**
     * @return array{_notes: string, data: array<string, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('ID of the comment to mark resolved (returned by leave_comment or get_comments).')]
        #[Validate(ExistingComment::class)]
        #[Bind(CommentBinder::class)]
        CommentRecord|int $commentId,
    ): array|ToolOutput {
        if (! $commentId instanceof CommentRecord) {
            throw new \LogicException('Comment was not bound before invocation.');
        }

        $record = $commentId;

        if ($record->status === CommentRecord::STATUS_RESOLVED) {
            return [
                '_notes' => sprintf(
                    'Comment #%d was already resolved (by %s on %s). No change.',
                    $record->id,
                    $record->resolvedBy ?? 'unknown',
                    $record->resolvedAt ?? 'unknown date',
                ),
                'data' => self::serialize($record),
            ];
        }

        $record->status = CommentRecord::STATUS_RESOLVED;
        $record->resolvedBy = CommentRecord::RESOLVED_BY_AGENT;
        $record->resolvedAt = Db::prepareDateForDb(new \DateTime());

        if (! $record->save()) {
            return new ToolOutput(
                'Could not resolve the comment: '.implode('; ', array_map(
                    static fn ($errors): string => implode(', ', (array) $errors),
                    $record->getErrors(),
                )),
                isError: true,
            );
        }

        // Notify the originating session that this comment is closed.
        // The agent is typically running inside a comment-thread fork
        // here, not the parent — without this note the parent's next
        // run would have no record of what happened in the fork. We
        // post into record->sessionId, which is the session that
        // created the comment (the parent when forks are in play).
        try {
            /** @var AgentLoop $loop */
            $loop = Craft::$container->get(AgentLoop::class);
            $scope = $record->fieldHandle !== null
                ? "field `{$record->fieldHandle}`"
                : 'entry-level note';
            $loop->appendSystemContext(
                $record->sessionId,
                "[Comment #{$record->id} on {$scope} was resolved by the agent in its discussion thread.] Original comment: \"{$record->body}\". No further action required on this thread unless the user follows up.",
            );
        } catch (\Throwable) {
            // Notification is best-effort — the comment is already
            // marked resolved in the DB, and the parent's next run can
            // recover from the comment table directly if needed.
        }

        return [
            '_notes' => sprintf(
                'Comment #%d marked resolved. The indicator on the entry edit screen will clear on the user\'s next refresh.',
                $record->id,
            ),
            'data' => self::serialize($record),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialize(CommentRecord $record): array
    {
        return [
            'id' => (int) $record->id,
            'elementId' => (int) $record->elementId,
            'isDraft' => (bool) $record->isDraft,
            'fieldHandle' => $record->fieldHandle,
            'blockPath' => $record->blockPath,
            'body' => $record->body,
            'status' => $record->status,
            'resolvedAt' => $record->resolvedAt,
            'resolvedBy' => $record->resolvedBy,
        ];
    }
}
