<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\PreviewRequestRecord;
use yii\web\Response;

class MessagesController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireLogin();

        $sessionId = $this->request->getRequiredQueryParam('sessionId');
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

        return $this->asJson([
            'messages' => $messages,
            'previewRequest' => $this->nextPreviewRequest($sessionId),
        ]);
    }

    /**
     * @return ?array{id: int, type: string, status: string, input: array<string, mixed>}
     */
    private function nextPreviewRequest(string $sessionId): ?array
    {
        /** @var PreviewService $service */
        $service = Craft::$container->get(PreviewService::class);
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
     * @return array{id: int, role: string, content: list<array<string, mixed>>, attachments: list<array<string, mixed>>, dateCreated: ?string}
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
