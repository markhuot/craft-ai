<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\records\SessionRecord;

beforeEach(function () {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    Craft::$app->getUser()->setIdentity($user);

    $this->session = new SessionRecord();
    $this->session->id = 'preview-ctrl-session';
    $this->session->active = true;
    $this->session->userId = 1;
    $this->session->save();

    $this->service = new PreviewService();
});

function postPreview(string $action, array $body) {
    return test()->http('post', 'admin')
        ->withCsrfToken()
        ->addHeader('Accept', 'application/json')
        ->setBody(['action' => 'craft-ai/preview/'.$action, ...$body])
        ->send();
}

it('completes a request with the result payload the front-end posts', function () {
    $id = $this->service->create($this->session->id, 'tu-2', 'open', ['url' => 'https://example.com']);

    $response = postPreview('respond', [
        'id' => $id,
        'status' => 'completed',
        'result' => json_encode(['loadedAt' => 1700000000, 'finalUrl' => 'https://example.com/x']),
    ]);

    $response->assertOk();

    $reloaded = PreviewRequestRecord::findOne(['id' => $id]);
    expect($reloaded->status)->toBe(PreviewRequestRecord::STATUS_COMPLETED);
    $payload = json_decode($reloaded->result, true);
    expect($payload['finalUrl'])->toBe('https://example.com/x');
});

it('errors a request with the front-end-supplied message', function () {
    $id = $this->service->create($this->session->id, 'tu-3', 'open', ['url' => 'https://example.com']);

    $response = postPreview('respond', [
        'id' => $id,
        'status' => 'errored',
        'result' => json_encode(['error' => 'Iframe load failed']),
    ]);

    $response->assertOk();

    $reloaded = PreviewRequestRecord::findOne(['id' => $id]);
    expect($reloaded->status)->toBe(PreviewRequestRecord::STATUS_ERRORED);
    expect(json_decode($reloaded->result, true)['error'])->toBe('Iframe load failed');
});

it('rejects an unknown status value', function () {
    $id = $this->service->create($this->session->id, 'tu-4', 'open', ['url' => 'https://example.com']);

    $threw = false;
    try {
        postPreview('respond', ['id' => $id, 'status' => 'not-a-real-status']);
    } catch (\yii\web\BadRequestHttpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $reloaded = PreviewRequestRecord::findOne(['id' => $id]);
    expect($reloaded->status)->toBe(PreviewRequestRecord::STATUS_PENDING);
});

it('refuses to resolve a request that belongs to another user', function () {
    $suffix = bin2hex(random_bytes(4));
    $elementsTable = Craft::$app->getDb()->getSchema()->getRawTableName('{{%elements}}');
    Craft::$app->getDb()->createCommand()->insert($elementsTable, [
        'type' => User::class,
        'enabled' => true,
        'archived' => false,
        'dateCreated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
        'dateUpdated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();
    $otherId = (int) Craft::$app->getDb()->getLastInsertID();
    $usersTable = Craft::$app->getDb()->getSchema()->getRawTableName('{{%users}}');
    Craft::$app->getDb()->createCommand()->insert($usersTable, [
        'id' => $otherId,
        'username' => 'preview-other-'.$suffix,
        'email' => 'preview-other-'.$suffix.'@example.com',
        'active' => true,
        'pending' => false,
        'locked' => false,
        'suspended' => false,
        'admin' => false,
        'dateCreated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
        'dateUpdated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
    ])->execute();

    $theirSession = new SessionRecord();
    $theirSession->id = 'preview-ctrl-other';
    $theirSession->active = true;
    $theirSession->userId = $otherId;
    $theirSession->save();

    $service = new PreviewService();
    $id = $service->create($theirSession->id, 'tu-5', 'open', ['url' => 'https://x.test']);

    $threw = false;
    try {
        postPreview('respond', [
            'id' => $id,
            'status' => 'completed',
            'result' => json_encode(['loadedAt' => 1, 'finalUrl' => 'https://x.test']),
        ]);
    } catch (\yii\web\NotFoundHttpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $reloaded = PreviewRequestRecord::findOne(['id' => $id]);
    expect($reloaded->status)->toBe(PreviewRequestRecord::STATUS_PENDING);
});
