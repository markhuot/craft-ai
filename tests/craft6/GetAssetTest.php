<?php

use CraftCms\Cms\Asset\Models\Volume;
use CraftCms\Cms\Filesystem\Filesystems\Local;
use CraftCms\Cms\Support\Facades\Filesystems;

use markhuot\craftai\tools\GetAsset;
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
    $this->registry->register(GetAsset::class);
    $this->registry->register(UpsertAsset::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-get-asset').'.jpg';
    copy(__DIR__.'/stubs/images/gray.jpg', $this->sourceFile);
});

afterEach(function () {
    if (isset($this->sourceFile) && is_file($this->sourceFile)) {
        @unlink($this->sourceFile);
    }
});

it('returns metadata + url for an existing asset', function () {
    $created = $this->registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => 'lookup.jpg',
        'sourcePath' => $this->sourceFile,
    ]);
    expect($created->isError)->toBeFalse($created->text);
    // upsert_asset returns {_notes, data: <PreviewSuggestion-wrapped asset>}.
    // PreviewSuggestion::wrap returns either ['notes' => ..., 'asset' => $data]
    // (off-CP and on-CP-with-url) or $data directly (on-CP-without-url).
    $createdData = json_decode($created->text, true)['data'];
    $assetData = $createdData['asset'] ?? $createdData;
    $id = (int) $assetData['id'];

    $output = $this->registry->execute('get_asset', ['id' => $id]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
    expect($payload['data']['id'])->toBe($id);
    expect($payload['data']['filename'])->toBe('lookup.jpg');
    expect($payload['data'])->toHaveKey('mimeType');
    expect($payload['data'])->toHaveKey('url');
    // Image assets should point the agent at get_image so vision-capable
    // providers can actually see the bytes, not just the URL.
    expect($payload['_notes'])->toContain('get_image');
});

it('returns an error for an unknown asset id', function () {
    $output = $this->registry->execute('get_asset', ['id' => 999999]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});
