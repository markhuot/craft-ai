<?php

use CraftCms\Cms\Asset\Models\Volume;
use CraftCms\Cms\Filesystem\Filesystems\Local;
use CraftCms\Cms\Support\Facades\Filesystems;

use craft\elements\Asset;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;

beforeEach(function () {
    $fs = new Local([
        'name' => 'Uploads FS',
        'handle' => 'uploadsFs',
        'path' => sys_get_temp_dir().'/craftai-fs-'.uniqid(),
    ]);
    Filesystems::saveFilesystem($fs);
    Volume::factory()->create(['name' => 'Uploads', 'handle' => 'uploads', 'fs' => 'uploadsFs']);

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertAsset::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-asset-src').'.jpg';
    copy(__DIR__.'/stubs/images/gray.jpg', $this->sourceFile);
});

afterEach(function () {
    if (isset($this->sourceFile) && is_file($this->sourceFile)) {
        @unlink($this->sourceFile);
    }
});

it('creates an asset from a local source path', function () {
    $output = $this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'hello.jpg',
        'sourcePath' => $this->sourceFile,
    ]);

    expect($output->isError)->toBeFalse($output->text);
    // CP context always wraps with at least a cpEditUrl note now, so
    // the asset payload lives at `data.asset` instead of `data`.
    $result = decode($output)['data']['asset'];
    expect($result['filename'])->toBe('hello.jpg');

    $asset = Asset::find()->id($result['id'])->status(null)->one();
    expect($asset->filename)->toBe('hello.jpg');
});

it('creates an asset with a title and alt', function () {
    $output = $this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'titled.jpg',
        'sourcePath' => $this->sourceFile,
        'title' => 'My Photo',
        'alt' => 'A gray square',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $asset = Asset::find()->id(decode($output)['data']['asset']['id'])->status(null)->one();
    expect($asset->title)->toBe('My Photo');
    expect($asset->alt)->toBe('A gray square');
});

it('returns an error for an unknown volume', function () {
    $result = $this->registry->execute('upsert_asset', [
        'volume' => 'nope',
        'filename' => 'x.jpg',
        'sourcePath' => $this->sourceFile,
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('nope');
});

it('returns an error when no file source is provided on create', function () {
    $result = $this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'x.jpg',
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('source');
});

it('returns an error when the source path does not exist', function () {
    $result = $this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'x.jpg',
        'sourcePath' => '/path/does/not/exist.jpg',
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('does not exist');
});

it('requires volume and filename when no id is given', function () {
    $result = $this->registry->execute('upsert_asset', []);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('Volume');
    expect($result->text)->toContain('Filename');
});

it('returns an error for an unknown asset id', function () {
    $result = $this->registry->execute('upsert_asset', ['id' => 999999, 'title' => 'Nope']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('updates an existing asset by id', function () {
    $created = decode($this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'original.jpg',
        'sourcePath' => $this->sourceFile,
        'title' => 'Original',
    ]))['data']['asset'];

    $output = $this->registry->execute('upsert_asset', [
        'id' => $created['id'],
        'title' => 'Updated',
        'alt' => 'Now described',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $asset = Asset::find()->id($created['id'])->status(null)->one();
    expect($asset->title)->toBe('Updated');
    expect($asset->alt)->toBe('Now described');
});

it('binds a volume by numeric ID', function () {
    $volume = Craft::$app->volumes->getVolumeByHandle('uploads');

    $output = $this->registry->execute('upsert_asset', [
        'volume' => $volume->id,
        'filename' => 'by-id.jpg',
        'sourcePath' => $this->sourceFile,
    ]);

    expect($output->isError)->toBeFalse($output->text);
    expect(decode($output)['data']['asset']['filename'])->toBe('by-id.jpg');
});

it('folds the open_preview prompt into _notes when the asset has a URL', function () {
    $volume = Craft::$app->volumes->getVolumeByHandle('uploads');
    $fs = $volume->getFs();
    $fs->hasUrls = true;
    $fs->url = 'https://example.test/uploads/';
    Craft::$app->fs->saveFilesystem($fs);

    $raw = $this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'previewable-'.uniqid().'.jpg',
        'sourcePath' => $this->sourceFile,
    ]);
    expect($raw->isError)->toBeFalse($raw->text);
    $payload = decode($raw);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['data'])->toHaveKey('asset');
    expect($payload['data'])->not->toHaveKey('notes');

    expect($payload['_notes'])->toContain('open_preview');
    expect($payload['_notes'])->toContain($payload['data']['asset']['url']);
    expect($payload['data']['asset']['url'])->toStartWith('https://example.test/uploads/');
});

it('skips the open_preview prompt on MCP and keeps _notes scoped to the tool narration', function () {
    /** @var ToolContext $context */
    $context = Craft::$container->get(ToolContext::class);
    $context->begin(null, null, ClientType::MCP);

    $payload = decode($this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'mcp-'.uniqid().'.jpg',
        'sourcePath' => $this->sourceFile,
    ]));

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->not->toContain('open_preview');
    expect($payload['_notes'])->not->toContain('review and edit');
    expect($payload['_notes'])->toMatch('/(Created|Updated) asset id=/');
});
