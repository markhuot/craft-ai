<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    Craft::$app->getUser()->setIdentity($user);

    Volume::factory()->name('Uploads')->handle('uploads')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertAsset::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-asset-info').'.jpg';
    copy(__DIR__.'/../vendor/markhuot/craft-pest-core/stubs/images/gray.jpg', $this->sourceFile);
});

afterEach(function () {
    if (isset($this->sourceFile) && is_file($this->sourceFile)) {
        @unlink($this->sourceFile);
    }
});

function createTestAsset(string $filename, $registry, $sourceFile): int
{
    $output = $registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => $filename,
        'sourcePath' => $sourceFile,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    // CP context always wraps the upsert payload with a cpEditUrl note,
    // so the asset record lives at `data.asset.id` rather than `data.id`.
    return (int) json_decode($output->text, true)['data']['asset']['id'];
}

it('returns metadata + thumbUrl for the requested asset ids', function () {
    $id = createTestAsset('info-1.jpg', $this->registry, $this->sourceFile);

    $response = test()->http('get', 'admin')
        ->addHeader('Accept', 'application/json')
        ->setBody([
            'action' => 'craft-ai/assets/info',
            'ids' => json_encode([$id]),
        ])
        ->send();

    $response->assertOk();
    $response->assertJsonPath('assets.0.id', $id);
    $response->assertJsonPath('assets.0.filename', 'info-1.jpg');
    $response->assertJsonPath('assets.0.kind', 'image');
});

it('preserves the order of requested ids', function () {
    $a = createTestAsset('first.jpg', $this->registry, $this->sourceFile);
    $b = createTestAsset('second.jpg', $this->registry, $this->sourceFile);

    $response = test()->http('get', 'admin')
        ->addHeader('Accept', 'application/json')
        ->setBody([
            'action' => 'craft-ai/assets/info',
            'ids' => json_encode([$b, $a]),
        ])
        ->send();

    $response->assertOk();
    $response->assertJsonPath('assets.0.id', $b);
    $response->assertJsonPath('assets.1.id', $a);
});

it('returns an empty list when given no ids', function () {
    $response = test()->http('get', 'admin')
        ->addHeader('Accept', 'application/json')
        ->setBody(['action' => 'craft-ai/assets/info'])
        ->send();

    $response->assertOk();
    $response->assertJsonPath('assets', []);
});

it('drops ids that do not resolve to an asset', function () {
    $id = createTestAsset('exists.jpg', $this->registry, $this->sourceFile);

    $response = test()->http('get', 'admin')
        ->addHeader('Accept', 'application/json')
        ->setBody([
            'action' => 'craft-ai/assets/info',
            'ids' => json_encode([$id, 999999]),
        ])
        ->send();

    $response->assertOk();
    $response->assertJsonCount(1, 'assets');
    $response->assertJsonPath('assets.0.id', $id);
});

it('rejects an unauthenticated request', function () {
    // AssetsController doesn't scope by user (asset metadata is CMS-wide,
    // not session-bound), but the endpoint still requires authentication —
    // a guest must not be able to enumerate asset details. Drop the test
    // identity established in TestCase::setUp before hitting the action.
    Craft::$app->getUser()->setIdentity(null);

    $threw = false;
    try {
        test()->http('get', 'admin')
            ->addHeader('Accept', 'application/json')
            ->setBody([
                'action' => 'craft-ai/assets/info',
                'ids' => '1',
            ])
            ->send();
    } catch (\yii\web\ForbiddenHttpException) {
        $threw = true;
    } catch (\yii\base\UserException) {
        // Yii's requireLogin throws different concrete exception types
        // depending on the request context; either flavor proves the
        // guard fired.
        $threw = true;
    }

    expect($threw)->toBeTrue();
});
