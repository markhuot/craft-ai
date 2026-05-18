<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\helpers\CommentMarkdown;
use markhuot\craftai\helpers\CommentScope;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\records\MessageRecord;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * HTTP surface for review comments left by the agent.
 *
 * The CP entry edit screen calls into this controller to:
 *   - list open comments for the element being edited (so it can render
 *     indicators next to flagged fields),
 *   - resolve a comment when the user clicks the resolve button,
 *   - reply to a comment, which threads the reply back into the
 *     originating chat session and re-runs the agent so it can respond.
 *
 * Agent-side mutations (leave / resolve / get) flow through the tool
 * registry instead, so they sit inside the LLM's normal tool_use loop.
 */
class CommentsController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    /**
     * GET craft-ai/comments
     *
     * Query params:
     *   - elementId (int, required): canonical entry id or draftId.
     *   - isDraft   (0|1, optional, default 0): whether elementId is a draftId.
     *   - status    ('open'|'resolved'|'all', optional, default 'open').
     *
     * Returns the list of comments scoped to that element so the entry
     * edit screen JS can decorate the matching field DOM nodes.
     */
    public function actionIndex(): Response
    {
        $this->requireLogin();

        $elementIdRaw = $this->request->getRequiredQueryParam('elementId');
        if (! is_numeric($elementIdRaw)) {
            throw new BadRequestHttpException('elementId must be numeric.');
        }
        $elementId = (int) $elementIdRaw;
        $isDraft = (bool) $this->request->getQueryParam('isDraft', false);

        $statusRaw = $this->request->getQueryParam('status', 'open');
        $status = is_string($statusRaw) ? $statusRaw : 'open';

        if (! in_array($status, ['open', 'resolved', 'all'], true)) {
            throw new BadRequestHttpException('status must be one of: open, resolved, all.');
        }

        // Matrix blocks are entries: a comment on a field inside a block
        // is filed against the block's own elementId, not the page entry.
        // Walk the tree so the CP overlay can render dots on every level
        // in a single fetch.
        $pairs = CommentScope::pairsFor($elementId, $isDraft);

        $query = CommentRecord::find()
            ->orderBy(['id' => SORT_ASC]);

        $orClauses = ['or'];
        foreach ($pairs as [$id, $pairIsDraft]) {
            $orClauses[] = ['and', ['elementId' => $id], ['isDraft' => $pairIsDraft]];
        }
        $query->andWhere($orClauses);

        if ($status !== 'all') {
            $query->andWhere(['status' => $status]);
        }

        /** @var list<CommentRecord> $records */
        $records = $query->all();

        return $this->asJson([
            'comments' => array_map(self::serialize(...), $records),
        ]);
    }

    /**
     * POST craft-ai/comments/resolve
     *
     * Body params:
     *   - commentId (int, required)
     *
     * Marks the comment resolved by the user and appends a system note
     * into the originating session so the agent (when it next runs)
     * knows the user closed the loop on its feedback.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $commentIdRaw = $this->request->getRequiredBodyParam('commentId');
        if (! is_numeric($commentIdRaw)) {
            throw new BadRequestHttpException('commentId must be numeric.');
        }
        $commentId = (int) $commentIdRaw;
        $record = CommentRecord::findOne(['id' => $commentId]);

        if ($record === null) {
            throw new NotFoundHttpException("No comment found with id {$commentId}.");
        }

        if ($record->status !== CommentRecord::STATUS_RESOLVED) {
            $record->status = CommentRecord::STATUS_RESOLVED;
            $record->resolvedBy = CommentRecord::RESOLVED_BY_USER;
            $record->resolvedAt = Db::prepareDateForDb(new \DateTime());
            $record->save();

            $this->notifySessionOfResolve($record);
        }

        return $this->asJson([
            'ok' => true,
            'comment' => self::serialize($record),
        ]);
    }

    /**
     * POST craft-ai/comments/open-thread
     *
     * Body params:
     *   - commentId (int, required)
     *
     * Returns the id of the forked session that carries this comment's
     * discussion. On first call the fork is created lazily: we copy the
     * originating session's transcript up to the assistant turn that left
     * the comment, persist the fork id back onto the comment, and seed a
     * single system note inside the fork that pins which comment is in
     * play. Subsequent calls return the same fork id without copying again.
     *
     * The widget then opens against the returned session id and the user
     * chats normally — there's no special "reply" surface, just the same
     * chat UI scoped to a private fork.
     */
    public function actionOpenThread(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $commentIdRaw = $this->request->getRequiredBodyParam('commentId');
        if (! is_numeric($commentIdRaw)) {
            throw new BadRequestHttpException('commentId must be numeric.');
        }
        $commentId = (int) $commentIdRaw;

        $record = CommentRecord::findOne(['id' => $commentId]);
        if ($record === null) {
            throw new NotFoundHttpException("No comment found with id {$commentId}.");
        }

        $threadSessionId = $record->threadSessionId;
        $created = false;

        if ($threadSessionId === null || $threadSessionId === '') {
            /** @var AgentLoop $loop */
            $loop = Craft::$container->get(AgentLoop::class);

            // Pick the fork point. authorMessageId points at the assistant
            // turn whose tool_use created this comment, which is the
            // natural cutoff — copy everything up to and including that
            // turn so the fork sees the comment in context. If we somehow
            // don't have an authorMessageId (older comments predating that
            // column), fall back to the latest message in the parent.
            $throughMessageId = $record->authorMessageId !== null
                ? (int) $record->authorMessageId
                : (int) (MessageRecord::find()
                    ->where(['sessionId' => $record->sessionId])
                    ->max('id') ?? 0);

            if ($throughMessageId <= 0) {
                throw new BadRequestHttpException("Comment #{$commentId} has no anchor message to fork from.");
            }

            $forkId = $loop->forkSession(
                parentSessionId: $record->sessionId,
                throughMessageId: $throughMessageId,
                originatingCommentId: (int) $record->id,
            );

            if ($forkId === null) {
                throw new NotFoundHttpException("Could not locate session {$record->sessionId} for comment #{$commentId}.");
            }

            // Drop a system note into the fork pinning what's under
            // discussion. The agent's first reply will see this in the
            // history alongside its own earlier leave_comment tool_use,
            // and knows it can close the thread with resolve_comment.
            $scope = $record->fieldHandle !== null
                ? "field `{$record->fieldHandle}`"
                : 'entry-level note';
            $loop->appendSystemContext(
                $forkId,
                "[The user has opened comment #{$record->id} ({$scope}) for discussion in a dedicated thread.] Original comment body: \"{$record->body}\". Focus your responses on resolving this specific feedback. Mark it resolved with resolve_comment(commentId: {$record->id}) once the user is satisfied.",
            );

            $record->threadSessionId = $forkId;
            $record->save();
            $threadSessionId = $forkId;
            $created = true;
        }

        return $this->asJson([
            'ok' => true,
            'created' => $created,
            'threadSessionId' => $threadSessionId,
            'sessionUrl' => UrlHelper::cpUrl('ai/session/'.$threadSessionId),
            'comment' => self::serialize($record),
        ]);
    }

    private function notifySessionOfResolve(CommentRecord $record): void
    {
        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);

        $scope = $record->fieldHandle !== null
            ? "field `{$record->fieldHandle}`"
            : 'entry-level note';

        $note = "[User resolved comment #{$record->id} on {$scope}] Original comment: \"{$record->body}\". No further action required on this thread unless the user follows up.";

        // Post into the originating session so the parent agent (the one
        // that left the comment) sees the resolution next time it runs.
        $loop->appendSystemContext($record->sessionId, $note);

        // If a discussion fork was already opened for this comment,
        // notify it too. The fork agent might be mid-discussion when
        // the user resolved from outside, and we don't want it to keep
        // pursuing the now-closed thread.
        if ($record->threadSessionId !== null && $record->threadSessionId !== $record->sessionId) {
            $loop->appendSystemContext($record->threadSessionId, $note);
        }
    }

    /**
     * Helper: serialize a CommentRecord into JSON wire format. Used by both
     * the resolve endpoint (single comment) and the index endpoint (list).
     * Resolves the related entry into a title + edit URL when possible so
     * the UI can link directly to the source without a second round trip.
     *
     * @return array<string, mixed>
     */
    public static function serialize(CommentRecord $record): array
    {
        $title = null;
        $editUrl = null;
        $elementUid = null;

        $entry = $record->isDraft
            ? Entry::find()->draftId((int) $record->elementId)->status(null)->one()
            : Entry::find()->id((int) $record->elementId)->status(null)->one();

        if ($entry instanceof Entry) {
            $title = $entry->title;
            // Element UID. For Matrix-nested entries this is the value Craft
            // stamps onto `.matrixblock[data-uid="…"]`, so the CP overlay
            // can pin the indicator to the right block when `data-id`
            // matching isn't enough (e.g. cloned blocks awaiting save).
            $elementUid = $entry->uid;
            try {
                $editUrl = $entry->getCpEditUrl();
            } catch (\Throwable) {
                $editUrl = null;
            }
        }

        return [
            'id' => (int) $record->id,
            'sessionId' => $record->sessionId,
            'sessionUrl' => UrlHelper::cpUrl('ai/session/'.$record->sessionId),
            // Set once the user has opened this comment for discussion —
            // the fork session that carries the back-and-forth. Null until
            // first open; the popover uses this to decide whether to call
            // open-thread before pointing the chat widget at the session.
            'threadSessionId' => $record->threadSessionId,
            'threadSessionUrl' => $record->threadSessionId !== null
                ? UrlHelper::cpUrl('ai/session/'.$record->threadSessionId)
                : null,
            // Count of post-fork user turns — i.e. user replies the editor
            // has actually sent into the thread. Zero for unopened
            // comments. The popover shows this next to the comment row.
            'replyCount' => self::replyCount($record),
            'elementId' => (int) $record->elementId,
            'elementUid' => $elementUid,
            'isDraft' => (bool) $record->isDraft,
            'fieldHandle' => $record->fieldHandle,
            // `body` stays the raw markdown source so the agent-side tools
            // and any future surface can re-render it however they want.
            // `bodyHtml` is the pre-rendered, sanitized markup the comment
            // overlay JS drops into the popover via innerHTML — mirroring
            // the markdown rendering the main chat does client-side with
            // react-markdown.
            'body' => $record->body,
            'bodyHtml' => CommentMarkdown::render((string) $record->body),
            'status' => $record->status,
            'resolvedAt' => $record->resolvedAt,
            'resolvedBy' => $record->resolvedBy,
            'authorMessageId' => $record->authorMessageId === null ? null : (int) $record->authorMessageId,
            'dateCreated' => $record->dateCreated,
            'elementTitle' => $title,
            'elementEditUrl' => $editUrl,
        ];
    }

    /**
     * Number of user replies that have landed in the comment's fork
     * session since the fork was created. Computed from the session's
     * `forkPivotMessageId` — every user-role row with a higher id is a
     * fresh reply (the rows at or below the pivot are copies of parent
     * history).
     *
     * Returns 0 for comments that haven't been opened yet (no fork) or
     * for forks that somehow lack a pivot (shouldn't happen in practice
     * but we don't want to surface a misleading high count).
     */
    private static function replyCount(CommentRecord $record): int
    {
        if ($record->threadSessionId === null) {
            return 0;
        }

        $session = \markhuot\craftai\records\SessionRecord::findOne(['id' => $record->threadSessionId]);
        if ($session === null || $session->forkPivotMessageId === null) {
            return 0;
        }

        return (int) MessageRecord::find()
            ->where(['sessionId' => $record->threadSessionId, 'role' => 'user'])
            ->andWhere(['>', 'id', (int) $session->forkPivotMessageId])
            ->count();
    }
}
