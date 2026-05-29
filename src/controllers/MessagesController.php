<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\Plugin;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class MessagesController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireLogin();

        $sessionId = $this->request->getRequiredQueryParam('sessionId');
        if (! is_string($sessionId) || $sessionId === '') {
            throw new \yii\web\BadRequestHttpException('sessionId must be a non-empty string.');
        }
        $this->ensureSessionOwnership($sessionId);

        $afterParam = $this->request->getQueryParam('after', '0');
        $after = is_numeric($afterParam) ? (int) $afterParam : 0;

        /** @var list<MessageRecord> $records */
        $records = MessageRecord::find()
            ->where(['sessionId' => $sessionId])
            ->andWhere(['>', 'id', $after])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $messages = array_map(
            fn (MessageRecord $record): array => self::serializeMessage($record),
            $records,
        );

        /** @var PreviewService $preview */
        $preview = Craft::$container->get(PreviewService::class);

        // Sticky pointer at the most recent preview the agent surfaced —
        // either a trusted URL (open_preview) or a saved artifact
        // (open_artifact). Drives the toolbar globe so a page reload still lets
        // the user re-mount the iframe — the in-memory React state is wiped but
        // this row survives. The descriptor carries the framing (kind + title)
        // so an artifact reopens sandboxed and labeled, a URL plain.
        $lastPreview = $preview->lastSurfacedPreview($sessionId);

        return $this->asJson([
            'messages' => $messages,
            'previewRequest' => $this->nextPreviewRequest($sessionId, $preview),
            'lastPreview' => $lastPreview,
            // Back-compat scalar mirror of $lastPreview['url'] for older
            // front-ends that only read lastPreviewUrl.
            'lastPreviewUrl' => is_array($lastPreview) ? $lastPreview['url'] : null,
            // Context-window gauge data. The frontend computes "% used"
            // from the latest assistant turn's tokens / contextWindow and
            // shows a circular progress indicator on the prompt toolbar.
            'contextWindow' => self::contextWindow(),
            // Available slash commands for the prompt autocomplete. The
            // server is the source of truth so adding a new command
            // server-side surfaces it in the menu without a UI rebuild.
            'slashCommands' => self::slashCommandsPayload(),
            // Fork metadata so the chat UI can dim pre-fork history.
            // Null on top-level sessions (i.e. anything that isn't a
            // comment-thread fork) — the frontend skips the styling
            // entirely in that case.
            'session' => self::sessionMeta($sessionId),
        ]);
    }

    /**
     * Refuse to read/write a session owned by a different user. Mirrors
     * SessionsController's ownership check — sessions that don't exist yet
     * are allowed through so the React shell can render an empty composer
     * for a freshly-minted session id before the first send creates the
     * row. Legacy rows with `userId === null` are treated as "any owner."
     */
    private function ensureSessionOwnership(string $sessionId): void
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session === null) {
            return;
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $userId = $identity !== null ? (int) $identity->id : null;

        if ($session->userId !== null && $session->userId !== $userId) {
            throw new NotFoundHttpException("No session found with id {$sessionId}.");
        }
    }

    /**
     * Compact session-level info the chat surface uses for fork-aware
     * rendering. Today: just the parent + pivot pointers so the chat
     * component can render messages with id ≤ pivot at a lower opacity,
     * making the "where the comment thread starts" boundary visible.
     *
     * @return array{parentSessionId: ?string, originatingCommentId: ?int, forkPivotMessageId: ?int}
     */
    private static function sessionMeta(string $sessionId): array
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session === null) {
            return [
                'parentSessionId' => null,
                'originatingCommentId' => null,
                'forkPivotMessageId' => null,
            ];
        }
        return [
            'parentSessionId' => $session->parentSessionId,
            'originatingCommentId' => $session->originatingCommentId === null
                ? null
                : (int) $session->originatingCommentId,
            'forkPivotMessageId' => $session->forkPivotMessageId === null
                ? null
                : (int) $session->forkPivotMessageId,
        ];
    }

    /**
     * Serialize the built-in slash command catalog for the chat UI's
     * autocomplete menu. Mirrors the array shape declared in AgentLoop so
     * the menu and the dispatcher can't drift apart.
     *
     * @return list<array{name: string, description: string, takesArgs: bool}>
     */
    private static function slashCommandsPayload(): array
    {
        $payload = [];
        foreach (AgentLoop::availableSlashCommands() as $name => $meta) {
            $payload[] = [
                'name' => $name,
                'description' => $meta['description'],
                'takesArgs' => $meta['takesArgs'],
            ];
        }
        return $payload;
    }

    /**
     * Pull the configured context window from plugin settings. Returns null
     * when the setting (and the per-model fallback in Plugin::getSettingsArray)
     * can't resolve a value — the frontend hides its gauge in that case.
     */
    private static function contextWindow(): ?int
    {
        try {
            $settings = Plugin::getInstance()->getSettingsArray();
        } catch (\Throwable) {
            return null;
        }

        $window = $settings['contextWindow'] ?? null;

        return is_int($window) && $window > 0 ? $window : null;
    }

    /**
     * @return ?array{id: int, type: string, status: string, input: array<string, mixed>}
     */
    private function nextPreviewRequest(string $sessionId, PreviewService $service): ?array
    {
        $next = $service->nextActionable($sessionId);

        if ($next === null) {
            return null;
        }

        return [
            'id' => (int) $next->id,
            'type' => $next->type,
            'status' => $next->status,
            'input' => $service->decodeInput($next),
        ];
    }

    /**
     * Convert a MessageRecord to the wire format the React UI consumes. The
     * `attachments` array is the resolved asset metadata for any assetIds
     * column entries, ordered to match the original selection so the chat
     * thumbnails render in the expected sequence.
     *
     * @return array{id: int, role: string, content: list<array<string, mixed>>, attachments: list<array<string, mixed>>, dateCreated: ?string, inputTokens: ?int, outputTokens: ?int}
     */
    public static function serializeMessage(MessageRecord $record): array
    {
        /** @var list<array<string, mixed>> $content */
        $content = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => $record->id,
            'role' => $record->role,
            'content' => $content,
            'attachments' => self::resolveAttachments($record->assetIds),
            'dateCreated' => $record->dateCreated,
            // Frontend reads inputTokens off the most recent assistant
            // message to render the context-window progress gauge. Null
            // for user/system/summary rows — the frontend ignores those.
            'inputTokens' => $record->inputTokens === null ? null : (int) $record->inputTokens,
            'outputTokens' => $record->outputTokens === null ? null : (int) $record->outputTokens,
        ];
    }

    /**
     * @return list<array{id: int, label: string, filename: ?string, kind: ?string, mimeType: ?string, thumbUrl: ?string}>
     */
    private static function resolveAttachments(?string $assetIdsJson): array
    {
        if ($assetIdsJson === null || $assetIdsJson === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($assetIdsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $entry) {
            if (is_int($entry) && $entry > 0) {
                $ids[] = $entry;
            } elseif (is_string($entry) && ctype_digit($entry)) {
                $intVal = (int) $entry;
                if ($intVal > 0) {
                    $ids[] = $intVal;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $assets = Asset::find()->id($ids)->status(null)->all();
        $service = Craft::$app->getAssets();

        $byId = [];
        foreach ($assets as $asset) {
            if ($asset->id === null) {
                continue;
            }
            $byId[$asset->id] = [
                'id' => $asset->id,
                'label' => $asset->title ?: $asset->filename ?: "Asset #{$asset->id}",
                'filename' => $asset->filename,
                'kind' => $asset->kind,
                'mimeType' => $asset->getMimeType(),
                'thumbUrl' => $service->getThumbUrl($asset, 60, 60, true),
            ];
        }

        $payload = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $payload[] = $byId[$id];
                continue;
            }
            // Asset disappeared (deleted, no permission, etc.). Surface a
            // placeholder so the UI can still show "missing attachment"
            // rather than silently dropping it from the user's history.
            $payload[] = [
                'id' => $id,
                'label' => "Asset #{$id}",
                'filename' => null,
                'kind' => null,
                'mimeType' => null,
                'thumbUrl' => null,
            ];
        }

        return $payload;
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $userMessage = $this->request->getRequiredBodyParam('message');

        if (! is_string($sessionId) || ! is_string($userMessage)) {
            throw new \yii\web\BadRequestHttpException('sessionId and message must be strings.');
        }
        $this->ensureSessionOwnership($sessionId);

        $async = (bool) $this->request->getBodyParam('async', false);

        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);
        $loop->appendUserMessage($sessionId, $userMessage);

        if ($async) {
            $identity = Craft::$app->getUser()->getIdentity();
            Craft::$app->getQueue()->push(new AgentJob([
                'sessionId' => $sessionId,
                'userId' => $identity !== null ? (int) $identity->id : null,
            ]));

            return $this->asJson(['queued' => true, 'sessionId' => $sessionId]);
        }

        $loop->run($sessionId);

        return $this->asJson(['ok' => true, 'sessionId' => $sessionId]);
    }
}
