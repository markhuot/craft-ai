<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;

if (! function_exists('seedImageVolume')) {
    /**
     * Create a Local filesystem backed by a real temp directory and a Volume
     * that references it by handle, then refresh Craft's volume/fs caches so
     * the legacy services (Craft::$app->volumes / ->fs) the tools call resolve
     * a writable volume. The native Volume::factory() only writes
     * `fs => Local::class` and never registers an actual filesystem, so assets
     * cannot be written to it ("Volume is missing or has an invalid filesystem
     * handle.").
     */
    function seedImageVolume(string $handle, string $name): \CraftCms\Cms\Asset\Models\Volume
    {
        $path = sys_get_temp_dir().'/craftai-vol-'.$handle.'-'.bin2hex(random_bytes(4));
        \craft\helpers\FileHelper::createDirectory($path);

        $fs = new \CraftCms\Cms\Filesystem\Filesystems\Local();
        $fs->name = $name;
        $fs->handle = $handle;
        $fs->path = $path;
        $fs->hasUrls = false;
        \CraftCms\Cms\Support\Facades\Filesystems::saveFilesystem($fs);

        $volume = \CraftCms\Cms\Asset\Models\Volume::factory()->create([
            'name' => $name,
            'handle' => $handle,
            'fs' => $handle,
        ]);

        // Drop the volume service's memoized snapshot so the next handle
        // lookup re-reads from the DB and sees this (and any sibling) volume.
        \CraftCms\Cms\Support\Facades\Volumes::reset();

        return $volume;
    }
}

beforeEach(function () {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    $this->loginCraftUser((int) $user->id);

    seedImageVolume('uploads', 'Uploads');

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertAsset::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-asset-info').'.jpg';
    copy(__DIR__.'/stubs/images/gray.jpg', $this->sourceFile);
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

    $response = test()->getJson('admin?action=craft-ai/assets/info&ids='.urlencode(json_encode([$id])));

    $response->assertOk();
    $response->assertJsonPath('assets.0.id', $id);
    $response->assertJsonPath('assets.0.filename', 'info-1.jpg');
    $response->assertJsonPath('assets.0.kind', 'image');
});

it('preserves the order of requested ids', function () {
    $a = createTestAsset('first.jpg', $this->registry, $this->sourceFile);
    $b = createTestAsset('second.jpg', $this->registry, $this->sourceFile);

    $response = test()->getJson('admin?action=craft-ai/assets/info&ids='.urlencode(json_encode([$b, $a])));

    $response->assertOk();
    $response->assertJsonPath('assets.0.id', $b);
    $response->assertJsonPath('assets.1.id', $a);
});

it('returns an empty list when given no ids', function () {
    $response = test()->getJson('admin?action=craft-ai/assets/info');

    $response->assertOk();
    $response->assertJsonPath('assets', []);
});

it('drops ids that do not resolve to an asset', function () {
    $id = createTestAsset('exists.jpg', $this->registry, $this->sourceFile);

    $response = test()->getJson('admin?action=craft-ai/assets/info&ids='.urlencode(json_encode([$id, 999999])));

    $response->assertOk();
    $response->assertJsonCount(1, 'assets');
    $response->assertJsonPath('assets.0.id', $id);
});

it('rejects an unauthenticated request', function () {
    // AssetsController doesn't scope by user (asset metadata is CMS-wide,
    // not session-bound), but the endpoint still requires authentication —
    // a guest must not be able to enumerate asset details. Drop the test
    // identity established in TestCase::setUp before hitting the action.
    Craft::$app->getUser()->logout(false);

    $threw = false;
    try {
        $this->withoutExceptionHandling();
        test()->getJson('admin?action=craft-ai/assets/info&ids=1');
    } catch (\Illuminate\Auth\AuthenticationException) {
        // Laravel's auth middleware rejects the guest before the Craft
        // controller runs — the same guard, surfaced one layer out.
        $threw = true;
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
