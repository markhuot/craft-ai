<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\web\Controller;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\records\SessionRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Out-of-band channel between the CP front-end and blocking tools running in
 * the agent-loop queue worker. The front-end picks up a pending request via
 * the `previewRequest` field on `/craft-ai/messages`, mounts the iframe /
 * reads its contents, and POSTs `respond` here with the payload the
 * corresponding tool is waiting for.
 *
 * Ownership is checked on every call — a user can't resolve another user's
 * preview even if they guess the request id. The agent loop is the sole
 * creator of these rows, so there's no claim/start handshake; the row goes
 * straight from `pending` to `completed`/`errored`.
 */
class PreviewController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    public function actionRespond(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();
        $this->requireAcceptsJson();

        $request = $this->loadOwnedRequest();

        $status = $this->request->getRequiredBodyParam('status');
        if (! is_string($status) || ($status !== 'completed' && $status !== 'errored')) {
            throw new \yii\web\BadRequestHttpException('status must be "completed" or "errored".');
        }

        $rawResult = $this->request->getBodyParam('result');
        $result = $this->normalizeResult($rawResult);

        /** @var PreviewService $service */
        $service = Craft::$container->get(PreviewService::class);

        if ($status === 'completed') {
            $service->complete($request->id, $result);

            return $this->asJson(['ok' => true]);
        }

        $message = is_string($result['error'] ?? null) && $result['error'] !== ''
            ? $result['error']
            : 'Preview failed.';
        $service->fail($request->id, $message);

        return $this->asJson(['ok' => true]);
    }

    private function loadOwnedRequest(): PreviewRequestRecord
    {
        $rawId = $this->request->getRequiredBodyParam('id');
        if (! is_numeric($rawId)) {
            throw new \yii\web\BadRequestHttpException('id must be numeric.');
        }
        $id = (int) $rawId;

        /** @var ?PreviewRequestRecord $record */
        $record = PreviewRequestRecord::findOne(['id' => $id]);
        if ($record === null) {
            throw new NotFoundHttpException('Preview request not found.');
        }

        $session = SessionRecord::findOne(['id' => $record->sessionId]);
        if ($session === null) {
            throw new NotFoundHttpException('Preview request not found.');
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $userId = $identity !== null ? (int) $identity->id : null;
        if ($session->userId !== null && $session->userId !== $userId) {
            throw new NotFoundHttpException('Preview request not found.');
        }

        return $record;
    }

    /**
     * The front-end posts result either as a JSON-encoded string in a single
     * FormData field (so the body parsing path matches the rest of the
     * `/craft-ai` controllers) or as an already-decoded array if the client
     * used `application/json`. Anything else is collapsed to an empty payload.
     *
     * @return array<string, mixed>
     */
    private function normalizeResult(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            if (! is_array($decoded)) {
                return [];
            }

            return $decoded;
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        return [];
    }
}
