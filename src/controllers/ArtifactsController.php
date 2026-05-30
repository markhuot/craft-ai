<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\web\Controller;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\SessionRecord;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves agent-authored HTML artifacts (e.g. a rendered revision diff) as a
 * standalone document.
 *
 * The HTML is untrusted model/CKEditor output, so it ships with a strict CSP
 * (no scripts, no external loads) and is meant to be rendered inside a
 * sandboxed iframe. Ownership is checked on every request — a user can only
 * view artifacts that belong to one of their own sessions, mirroring
 * {@see PreviewController::loadOwnedRequest()}.
 */
class ArtifactsController extends Controller
{
    use ResolvesRequestParams;

    public array|bool|int $allowAnonymous = false;

    public function actionView(?int $id = null): Response
    {
        $this->requireLogin();

        // $id binds from the `<id:\d+>` route capture on the real CP URL;
        // fall back to a request param so the action is also reachable via
        // `?action=…&id=…`.
        if ($id === null) {
            $id = $this->getIntParam('id');
        }
        if ($id === null) {
            throw new BadRequestHttpException('A numeric artifact id is required.');
        }

        $artifact = $this->loadOwnedArtifact($id);

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->content = $artifact->html;

        $mimeType = $artifact->mimeType !== '' ? $artifact->mimeType : 'text/html';

        $headers = $response->getHeaders();
        $headers->set('Content-Type', $mimeType.'; charset=UTF-8');
        // Untrusted HTML: no scripts, no external loads, inline styles + data:
        // images only. The sandboxed iframe is the primary guard; this CSP is
        // defense in depth so the document is inert even if framed loosely.
        $headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'");
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'no-referrer');

        $disposition = $this->request->getQueryParam('download') !== null ? 'attachment' : 'inline';
        $headers->set('Content-Disposition', $disposition.'; filename="diff.html"');

        return $response;
    }

    private function loadOwnedArtifact(int $id): ArtifactRecord
    {
        /** @var ?ArtifactRecord $artifact */
        $artifact = ArtifactRecord::findOne(['id' => $id]);
        if ($artifact === null) {
            throw new NotFoundHttpException('Artifact not found.');
        }

        $session = SessionRecord::findOne(['id' => $artifact->sessionId]);
        if ($session === null) {
            throw new NotFoundHttpException('Artifact not found.');
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $userId = $identity !== null ? (int) $identity->id : null;
        if ($session->userId !== null && $session->userId !== $userId) {
            throw new NotFoundHttpException('Artifact not found.');
        }

        return $artifact;
    }
}
