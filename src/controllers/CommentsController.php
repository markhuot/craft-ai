<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\helpers\CommentMarkdown;
use markhuot\craftai\helpers\CommentMarkerCleanup;
use markhuot\craftai\helpers\CommentScope;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
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
    /**
     * POST craft-ai/comments/create
     *
     * Body params:
     *   - elementId    (int, required)
     *   - isDraft      (0|1, optional, default 0)
     *   - fieldHandle  (string, required): the CKEditor field handle.
     *   - body         (string, required): the comment text the editor typed.
     *   - referenceId  (string, optional): a stable client-generated id
     *                  for the marker span. Server generates one when
     *                  missing so the editor can mint a UUID with crypto
     *                  OR fall back to a server-side UUID if it can't.
     *   - sessionId    (string, optional): reuse this existing session
     *                  instead of creating a fresh one. The widget
     *                  composer pre-creates a session on open so the
     *                  user can configure tool-mode / attach assets
     *                  through the standard chat plumbing before
     *                  committing the comment.
     *   - assetIds[]   (int[], optional): asset IDs the editor attached
     *                  through the composer's upload picker. The
     *                  endpoint files them onto a fresh user-role
     *                  message in the session so they show up as
     *                  attachments in the discussion thread.
     *   - toolMode     (string, optional): tool-permission scope to
     *                  apply to the new/existing session. Defaults to
     *                  the session's current value (or 'full' for
     *                  newly-created sessions).
     *   - enabledTools[] (string[], optional): explicit allowlist when
     *                  toolMode is 'custom'.
     *
     * User-initiated comments coming from the CKEditor toolbar plugin.
     * The CP "leave a comment" flow goes through this endpoint instead
     * of the agent's `leave_comment` tool because the editor — not an
     * LLM — is authoring the feedback. We still rely on a `SessionRecord`
     * so the resulting comment can carry its own discussion thread
     * (identical lifecycle to agent-created comments after this point).
     */
    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $elementIdRaw = $this->request->getRequiredBodyParam('elementId');
        if (! is_numeric($elementIdRaw)) {
            throw new BadRequestHttpException('elementId must be numeric.');
        }
        $elementId = (int) $elementIdRaw;
        $isDraft = (bool) $this->request->getBodyParam('isDraft', false);

        $fieldHandle = $this->request->getRequiredBodyParam('fieldHandle');
        if (! is_string($fieldHandle) || $fieldHandle === '') {
            throw new BadRequestHttpException('fieldHandle must be a non-empty string.');
        }

        $body = $this->request->getRequiredBodyParam('body');
        if (! is_string($body) || trim($body) === '') {
            throw new BadRequestHttpException('body must be a non-empty string.');
        }
        $body = trim($body);
        if (strlen($body) > 4000) {
            throw new BadRequestHttpException('body must be 4000 characters or fewer.');
        }

        $referenceIdRaw = $this->request->getBodyParam('referenceId');
        $referenceId = is_string($referenceIdRaw) && $referenceIdRaw !== ''
            ? $referenceIdRaw
            : \craft\helpers\StringHelper::UUID();

        if (strlen($referenceId) > 64) {
            throw new BadRequestHttpException('referenceId must be 64 characters or fewer.');
        }

        // Confirm the target element actually exists so we don't write
        // an orphan row. We accept either canonical entries or drafts;
        // the CKEditor plugin reads the page's `elementId` / `draftId`
        // hidden inputs the same way the overlay JS does today.
        $entryQuery = Entry::find()->status(null);
        if ($isDraft) {
            $entryQuery->draftId($elementId);
        } else {
            $entryQuery->id($elementId);
        }
        $entry = $entryQuery->one();
        if (! $entry instanceof Entry) {
            throw new NotFoundHttpException(sprintf(
                'No %s found with id %d.',
                $isDraft ? 'draft' : 'entry',
                $elementId,
            ));
        }

        // Stand up (or reuse) the session that owns this comment's
        // eventual discussion. The widget composer pre-creates a
        // session on open so the user can pick a tool-mode and stage
        // attachments through the regular chat plumbing — when it
        // submits, that sessionId rides along here so we don't lose
        // those preferences. Direct callers (older CKEditor builds,
        // tests) skip sessionId and we mint a fresh one as before.
        $userId = Craft::$app->getUser()->getId();
        $userIdInt = is_numeric($userId) ? (int) $userId : null;

        $providedSessionId = $this->request->getBodyParam('sessionId');
        $session = null;
        if (is_string($providedSessionId) && $providedSessionId !== '') {
            $session = SessionRecord::findOne(['id' => $providedSessionId]);
            if ($session === null) {
                throw new NotFoundHttpException("No session found with id {$providedSessionId}.");
            }
            // Don't let one user write a comment against another user's
            // session — sessions are per-user. `null` is legacy (older
            // rows) so we treat it as "any owner allowed."
            if ($session->userId !== null && $session->userId !== $userIdInt) {
                throw new NotFoundHttpException("No session found with id {$providedSessionId}.");
            }
        }

        if ($session === null) {
            $sessionId = \craft\helpers\StringHelper::UUID();
            $session = new SessionRecord();
            $session->id = $sessionId;
            $session->active = false;
            $session->stopRequested = false;
            $session->userId = $userIdInt;
            $session->toolMode = 'full';
            $session->clientType = 'cp';
            if (! $session->save()) {
                throw new BadRequestHttpException(
                    'Could not create comment session: '.implode('; ', array_map(
                        static fn ($errors): string => implode(', ', (array) $errors),
                        $session->getErrors(),
                    )),
                );
            }
        }
        $sessionId = (string) $session->id;

        // Apply tool-mode + custom allowlist when the composer sent them.
        // We accept the same modes the SessionsController whitelists so a
        // stale client can't sneak through an unsupported value.
        $toolModeParam = $this->request->getBodyParam('toolMode');
        if (is_string($toolModeParam) && $toolModeParam !== '') {
            $allowedModes = ['full', 'draft', 'readonly', 'custom'];
            if (! in_array($toolModeParam, $allowedModes, true)) {
                throw new BadRequestHttpException('toolMode must be one of: '.implode(', ', $allowedModes));
            }
            $session->toolMode = $toolModeParam;
            $enabledToolsParam = $this->request->getBodyParam('enabledTools');
            if (is_array($enabledToolsParam)) {
                $names = array_values(array_filter(
                    array_map(static fn ($v): string => is_string($v) ? $v : '', $enabledToolsParam),
                    static fn (string $v): bool => $v !== '',
                ));
                if ($names === []) {
                    $session->enabledTools = null;
                } else {
                    $encoded = json_encode($names);
                    $session->enabledTools = $encoded !== false ? $encoded : null;
                }
            }
            $session->save();
        }

        // Asset attachments — strict numeric filter so a malformed
        // payload doesn't sneak `assetIds[]=42; DROP TABLE` style
        // values through ActiveRecord's JSON encoder.
        $assetIdsRaw = $this->request->getBodyParam('assetIds');
        $assetIds = [];
        if (is_array($assetIdsRaw)) {
            foreach ($assetIdsRaw as $raw) {
                if (is_numeric($raw)) {
                    $id = (int) $raw;
                    if ($id > 0) $assetIds[] = $id;
                }
            }
        }

        $record = new CommentRecord();
        $record->sessionId = $sessionId;
        $record->elementId = $elementId;
        $record->isDraft = $isDraft;
        $record->fieldHandle = $fieldHandle;
        $record->referenceId = $referenceId;
        $record->body = $body;
        $record->status = CommentRecord::STATUS_OPEN;

        if (! $record->save()) {
            throw new BadRequestHttpException(
                'Could not save comment: '.implode('; ', array_map(
                    static fn ($errors): string => implode(', ', (array) $errors),
                    $record->getErrors(),
                )),
            );
        }

        // Seed the session with a system note so the agent has the
        // anchor when the editor later opens the thread to discuss it.
        // The thread fork itself happens lazily in actionOpenThread —
        // for user-authored comments the "parent" session is empty
        // bookkeeping, so the fork copies nothing of value but keeps
        // the data model consistent with agent-left comments.
        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);
        $loop->appendSystemContext(
            $sessionId,
            sprintf(
                "[Editor left a comment on a span in field `%s` of %s #%d] Comment body: \"%s\". The editor will open this thread to discuss the feedback — respond when they reply, and call resolve_comment(commentId: %d) once they're satisfied.",
                $fieldHandle,
                $isDraft ? 'draft' : 'entry',
                $elementId,
                $body,
                (int) $record->id,
            ),
        );

        // Persist the editor's authored body as a real user turn in
        // the session so on first thread-open the conversation looks
        // like a normal chat history — the editor's own message at
        // the top, attachments included. Without this the discussion
        // fork would start from the system note alone, which reads
        // awkwardly back ("you said…" but no prior user turn).
        if ($body !== '') {
            $loop->appendUserMessage($sessionId, $body, $assetIds);
        }

        return $this->asJson([
            'ok' => true,
            'comment' => self::serialize($record),
        ]);
    }

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

        $this->ensureSessionOwnership($record->sessionId, "No comment found with id {$commentId}.");

        if ($record->status !== CommentRecord::STATUS_RESOLVED) {
            $record->status = CommentRecord::STATUS_RESOLVED;
            $record->resolvedBy = CommentRecord::RESOLVED_BY_USER;
            $record->resolvedAt = Db::prepareDateForDb(new \DateTime());
            $record->save();

            $this->notifySessionOfResolve($record);
            // Strip the marker span from the entry's saved HTML so the
            // yellow highlight disappears for every viewer on next
            // page load. Saving the element bumps `dateUpdated`,
            // which is what Craft's element editor polls against to
            // surface the "this entry was updated, reload?" toast on
            // the open CP edit screen.
            CommentMarkerCleanup::unwrapResolved($record);
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

        $this->ensureSessionOwnership($record->sessionId, "No comment found with id {$commentId}.");

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
            if ($record->authorMessageId !== null) {
                $throughMessageId = (int) $record->authorMessageId;
            } else {
                $maxId = MessageRecord::find()
                    ->where(['sessionId' => $record->sessionId])
                    ->max('id');
                $throughMessageId = is_numeric($maxId) ? (int) $maxId : 0;
            }

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

    /**
     * Refuse to act on a comment whose originating session belongs to a
     * different user. Legacy rows with `session.userId === null` are treated
     * as "any owner allowed" so older data keeps working; comments whose
     * session has been deleted (orphans) also fall through — there's no
     * owner left to defend, and the user still needs a way to clear the
     * dangling row. The 404-shaped response mirrors the SessionsController
     * / PreviewController pattern — we never reveal that the comment
     * exists for someone else.
     */
    private function ensureSessionOwnership(string $sessionId, string $notFoundMessage): void
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session === null) {
            return;
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $userId = $identity !== null ? (int) $identity->id : null;

        if ($session->userId !== null && $session->userId !== $userId) {
            throw new NotFoundHttpException($notFoundMessage);
        }
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
            // Stable in-field id the CKEditor plugin stamps onto a
            // `<span data-craft-ai-comment-id="…">` wrapper. Null for
            // pre-span comments (field-level or whole-entry notes).
            'referenceId' => $record->referenceId,
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
