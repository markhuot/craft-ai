<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\SessionRecord;

beforeEach(function () {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    Craft::$app->getUser()->setIdentity($user);

    $this->session = new SessionRecord();
    $this->session->id = 'artifact-ctrl-session';
    $this->session->active = true;
    $this->session->userId = 1;
    $this->session->save(false);
});

function makeArtifact(string $sessionId, string $html, string $title = 'Diff'): int
{
    $artifact = new ArtifactRecord();
    $artifact->sessionId = $sessionId;
    $artifact->title = $title;
    $artifact->html = $html;
    $artifact->mimeType = 'text/html';
    $artifact->save(false);

    return (int) $artifact->id;
}

it('serves the stored HTML with a locked-down CSP', function () {
    $id = makeArtifact($this->session->id, '<!doctype html><html><body><h1>Diff body</h1></body></html>');

    $response = test()->get('admin?action=craft-ai/artifacts/view&id='.$id);

    $response->assertOk();
    expect((string) $response->content)->toContain('Diff body');

    expect($response->headers->get('Content-Type'))->toContain('text/html');
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'");
    expect($csp)->toContain("style-src 'unsafe-inline'");
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('diff.html');
});

it('offers the artifact as a download when ?download is present', function () {
    $id = makeArtifact($this->session->id, '<html><body>x</body></html>');

    $response = test()->get('admin?action=craft-ai/artifacts/view&download=1&id='.$id);

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
});

it('404s for an unknown artifact id', function () {
    $threw = false;
    try {
        test()->get('admin?action=craft-ai/artifacts/view&id=999999');
    } catch (\yii\web\NotFoundHttpException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('refuses to serve an artifact belonging to another user', function () {
    $otherId = createOtherUser('artifact');

    $theirSession = new SessionRecord();
    $theirSession->id = 'artifact-ctrl-other';
    $theirSession->active = true;
    $theirSession->userId = $otherId;
    $theirSession->save(false);

    $id = makeArtifact($theirSession->id, '<html><body>secret</body></html>');

    $threw = false;
    try {
        test()->get('admin?action=craft-ai/artifacts/view&id='.$id);
    } catch (\yii\web\NotFoundHttpException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});
