<?php

use craft\elements\Asset;
use markhuot\craftai\tools\DeleteAssets;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    Volume::factory()->name('Uploads')->handle('uploads')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertAsset::class);
    $this->registry->register(DeleteAssets::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-asset-src').'.jpg';
    copy(__DIR__.'/../vendor/markhuot/craft-pest-core/stubs/images/gray.jpg', $this->sourceFile);
});

afterEach(function () {
    if (isset($this->sourceFile) && is_file($this->sourceFile)) {
        @unlink($this->sourceFile);
    }
});

function createAsset(ToolRegistry $registry, string $filename, string $sourceFile): array
{
    $payload = json_decode($registry->execute('upsert_asset', [
        'volume' => 'uploads',
        'filename' => $filename,
        'sourcePath' => $sourceFile,
    ])->text, true);

    // UpsertAsset wraps via PreviewSuggestion: outer {_notes, data: {notes, asset}}
    // when client is not CP, data is {notes, asset}; the asset record carries the id.
    $data = $payload['data'] ?? [];

    return $data['asset'] ?? $data;
}

it('deletes a list of assets', function () {
    $a = createAsset($this->registry, 'a.jpg', $this->sourceFile);
    $b = createAsset($this->registry, 'b.jpg', $this->sourceFile);

    $output = $this->registry->execute('delete_assets', ['ids' => [$a['id'], $b['id']]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results'][(string) $a['id']]['deleted'])->toBeTrue();
    expect($payload['data']['results'][(string) $b['id']]['deleted'])->toBeTrue();

    expect(Asset::find()->id($a['id'])->status(null)->exists())->toBeFalse();
    expect(Asset::find()->id($b['id'])->status(null)->exists())->toBeFalse();
});

it('reports an error for unknown ids without aborting the batch', function () {
    $a = createAsset($this->registry, 'a.jpg', $this->sourceFile);

    $output = $this->registry->execute('delete_assets', ['ids' => [$a['id'], 999999]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results'][(string) $a['id']]['deleted'])->toBeTrue();
    expect($payload['data']['results']['999999']['deleted'])->toBeFalse();
    expect($payload['data']['results']['999999']['error'])->toContain('999999');
});

it('hard-deletes when hardDelete is true', function () {
    $a = createAsset($this->registry, 'a.jpg', $this->sourceFile);

    $output = $this->registry->execute('delete_assets', [
        'ids' => [$a['id']],
        'hardDelete' => true,
    ]);

    expect($output->isError)->toBeFalse($output->text);
    expect(Asset::find()->id($a['id'])->status(null)->trashed(null)->exists())->toBeFalse();
});
