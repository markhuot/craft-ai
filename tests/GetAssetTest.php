<?php

use markhuot\craftai\tools\GetAsset;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    Volume::factory()->name('Uploads')->handle('uploads')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(GetAsset::class);
    $this->registry->register(UpsertAsset::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-get-asset').'.jpg';
    copy(__DIR__.'/../vendor/markhuot/craft-pest-core/stubs/images/gray.jpg', $this->sourceFile);
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
});

it('returns an error for an unknown asset id', function () {
    $output = $this->registry->execute('get_asset', ['id' => 999999]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});
