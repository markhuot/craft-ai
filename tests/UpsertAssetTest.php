<?php

use craft\elements\Asset;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    Volume::factory()->name('Uploads')->handle('uploads')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertAsset::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-asset-src').'.jpg';
    copy(__DIR__.'/../vendor/markhuot/craft-pest-core/stubs/images/gray.jpg', $this->sourceFile);
});

afterEach(function () {
    if (isset($this->sourceFile) && is_file($this->sourceFile)) {
        @unlink($this->sourceFile);
    }
});

function decodeAsset(ToolOutput $output): array
{
    return json_decode($output->text, true);
}

it('creates an asset from a local source path', function () {
    $output = $this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'hello.jpg',
        'sourcePath' => $this->sourceFile,
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $result = decodeAsset($output);
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
    $asset = Asset::find()->id(decodeAsset($output)['id'])->status(null)->one();
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
    $created = decodeAsset($this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'original.jpg',
        'sourcePath' => $this->sourceFile,
        'title' => 'Original',
    ]));

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
    expect(decodeAsset($output)['filename'])->toBe('by-id.jpg');
});
