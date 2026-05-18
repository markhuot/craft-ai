<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\helpers\CommentMarkdown;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\CommentRecord;
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

        $query = CommentRecord::find()
            ->where(['elementId' => $elementId, 'isDraft' => $isDraft])
            ->orderBy(['id' => SORT_ASC]);

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
     * POST craft-ai/comments/reply
     *
     * Body params:
     *   - commentId (int, required)
     *   - message   (string, required)
     *
     * Persists the user's reply as a user-role message in the comment's
     * original session and queues the agent loop. The agent picks up
     * normally on the next run — its system prompt sees the conversation
     * history including the new reply, plus the system context note that
     * pins which comment the reply refers to.
     */
    public function actionReply(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $commentIdRaw = $this->request->getRequiredBodyParam('commentId');
        if (! is_numeric($commentIdRaw)) {
            throw new BadRequestHttpException('commentId must be numeric.');
        }
        $commentId = (int) $commentIdRaw;

        $messageRaw = $this->request->getRequiredBodyParam('message');
        if (! is_string($messageRaw)) {
            throw new BadRequestHttpException('message must be a string.');
        }
        $message = trim($messageRaw);

        if ($message === '') {
            throw new BadRequestHttpException('message must not be empty.');
        }

        $record = CommentRecord::findOne(['id' => $commentId]);
        if ($record === null) {
            throw new NotFoundHttpException("No comment found with id {$commentId}.");
        }

        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);

        // Stamp the reply with a short reference so the agent knows which
        // comment it belongs to — the LLM doesn't have direct DB access
        // and needs the reference to pair the reply against the original
        // tool_use that created the comment.
        $scope = $record->fieldHandle !== null
            ? "field `{$record->fieldHandle}`"
            : 'entry-level note';
        $element = $record->isDraft ? "draft #{$record->elementId}" : "entry #{$record->elementId}";

        $reply = "Re: comment #{$record->id} on {$element} ({$scope}): {$message}";

        $loop->appendUserMessage($record->sessionId, $reply);
        $loop->appendSystemContext(
            $record->sessionId,
            "[User replied to comment #{$record->id}] Original comment: \"{$record->body}\". You can mark it resolved with resolve_comment(commentId: {$record->id}) once the user's reply has been addressed.",
        );

        $identity = Craft::$app->getUser()->getIdentity();
        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $record->sessionId,
            'userId' => $identity !== null ? (int) $identity->id : null,
        ]));

        return $this->asJson([
            'ok' => true,
            'queued' => true,
            'sessionId' => $record->sessionId,
            'sessionUrl' => UrlHelper::cpUrl('ai/session/'.$record->sessionId),
        ]);
    }

    private function notifySessionOfResolve(CommentRecord $record): void
    {
        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);

        $scope = $record->fieldHandle !== null
            ? "field `{$record->fieldHandle}`"
            : 'entry-level note';

        $loop->appendSystemContext(
            $record->sessionId,
            "[User resolved comment #{$record->id} on {$scope}] Original comment: \"{$record->body}\". No further action required on this thread unless the user follows up.",
        );
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

        $entry = $record->isDraft
            ? Entry::find()->draftId((int) $record->elementId)->status(null)->one()
            : Entry::find()->id((int) $record->elementId)->status(null)->one();

        if ($entry instanceof Entry) {
            $title = $entry->title;
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
            'elementId' => (int) $record->elementId,
            'isDraft' => (bool) $record->isDraft,
            'fieldHandle' => $record->fieldHandle,
            'blockPath' => $record->blockPath,
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
}
