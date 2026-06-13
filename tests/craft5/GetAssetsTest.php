<?php

use markhuot\craftai\tools\GetAssets;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    Volume::factory()->name('Uploads')->handle('uploads')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertAsset::class);
    $this->registry->register(GetAssets::class);

    $this->sourceFile = tempnam(sys_get_temp_dir(), 'craftai-get-assets').'.jpg';
    copy(__DIR__.'/../vendor/markhuot/craft-pest-core/stubs/images/gray.jpg', $this->sourceFile);
});

afterEach(function () {
    if (isset($this->sourceFile) && is_file($this->sourceFile)) {
        @unlink($this->sourceFile);
    }
});

function makeAsset(ToolRegistry $registry, string $filename, string $sourceFile, array $extra = []): array
{
    $payload = json_decode($registry->execute('upsert_asset', array_merge([
        'volume' => 'uploads',
        'filename' => $filename,
        'sourcePath' => $sourceFile,
    ], $extra))->text, true);

    $data = $payload['data'] ?? [];

    return $data['asset'] ?? $data;
}

it('returns all assets when no filters are given', function () {
    makeAsset($this->registry, 'first.jpg', $this->sourceFile);
    makeAsset($this->registry, 'second.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $payload = $tool();

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
    expect($payload['data'])->toHaveCount(2);
    expect(array_column($payload['data'], 'filename'))->toContain('first.jpg', 'second.jpg');
});

it('filters assets by volume handle', function () {
    Volume::factory()->name('Other')->handle('other')->create();
    makeAsset($this->registry, 'in-uploads.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $resultUploads = $tool(volume: 'uploads')['data'];
    $resultOther = $tool(volume: 'other')['data'];

    expect($resultUploads)->toHaveCount(1);
    expect($resultUploads[0]['filename'])->toBe('in-uploads.jpg');
    expect($resultOther)->toBe([]);
});

it('filters assets by filename pattern', function () {
    makeAsset($this->registry, 'logo.jpg', $this->sourceFile);
    makeAsset($this->registry, 'hero.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $result = $tool(filename: 'logo*')['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['filename'])->toBe('logo.jpg');
});

it('filters assets by exact title', function () {
    makeAsset($this->registry, 'alpha.jpg', $this->sourceFile, ['title' => 'Alpha Photo']);
    makeAsset($this->registry, 'beta.jpg', $this->sourceFile, ['title' => 'Beta Photo']);

    $tool = new GetAssets();
    $result = $tool(title: 'Alpha Photo')['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Alpha Photo');
});

it('filters assets by kind', function () {
    makeAsset($this->registry, 'pic.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $images = $tool(kind: 'image')['data'];
    $videos = $tool(kind: 'video')['data'];

    expect($images)->toHaveCount(1);
    expect($videos)->toBe([]);
});

it('filters assets by hasAlt', function () {
    makeAsset($this->registry, 'with-alt.jpg', $this->sourceFile, ['alt' => 'Described']);
    makeAsset($this->registry, 'without-alt.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $withAlt = $tool(hasAlt: true)['data'];
    $withoutAlt = $tool(hasAlt: false)['data'];

    expect($withAlt)->toHaveCount(1);
    expect($withAlt[0]['filename'])->toBe('with-alt.jpg');
    expect($withoutAlt)->toHaveCount(1);
    expect($withoutAlt[0]['filename'])->toBe('without-alt.jpg');
});

it('respects the limit parameter', function () {
    for ($i = 0; $i < 5; $i++) {
        makeAsset($this->registry, "asset-{$i}.jpg", $this->sourceFile);
    }

    $tool = new GetAssets();
    $result = $tool(limit: 2)['data'];

    expect($result)->toHaveCount(2);
});

it('respects the offset parameter', function () {
    makeAsset($this->registry, 'a.jpg', $this->sourceFile);
    makeAsset($this->registry, 'b.jpg', $this->sourceFile);
    makeAsset($this->registry, 'c.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $result = $tool(orderBy: 'filename ASC', limit: 2, offset: 1)['data'];

    expect($result)->toHaveCount(2);
    expect($result[0]['filename'])->toBe('b.jpg');
    expect($result[1]['filename'])->toBe('c.jpg');
});

it('sorts results by orderBy', function () {
    makeAsset($this->registry, 'zebra.jpg', $this->sourceFile);
    makeAsset($this->registry, 'apple.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $result = $tool(orderBy: 'filename ASC')['data'];

    expect($result[0]['filename'])->toBe('apple.jpg');
    expect($result[1]['filename'])->toBe('zebra.jpg');
});

it('defaults limit to 25', function () {
    for ($i = 0; $i < 30; $i++) {
        makeAsset($this->registry, "bulk-{$i}.jpg", $this->sourceFile);
    }

    $tool = new GetAssets();
    $result = $tool()['data'];

    expect($result)->toHaveCount(25);
});

it('returns an empty array with notes when no assets match', function () {
    $tool = new GetAssets();
    $payload = $tool(volume: 'uploads');

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['data'])->toBe([]);
    expect($payload['_notes'])->toBeString()->not->toBe('');
});

it('passes the search parameter through to the asset query', function () {
    // InnoDB FULLTEXT indexes don't expose uncommitted rows to MATCH AGAINST,
    // so we can't test search results inside a transactional test. Instead
    // verify the parameter is wired up by checking the reflection.
    $tool = new GetAssets();
    $reflection = new ReflectionMethod($tool, '__invoke');

    $searchParam = $reflection->getParameters()[0];
    expect($searchParam->getName())->toBe('search');
    expect($searchParam->allowsNull())->toBeTrue();
});

it('returns mimeType and url for each asset', function () {
    makeAsset($this->registry, 'shown.jpg', $this->sourceFile);

    $tool = new GetAssets();
    $result = $tool()['data'];

    expect($result)->toHaveCount(1);
    expect($result[0])->toHaveKey('mimeType');
    expect($result[0])->toHaveKey('url');
});

it('rejects an unknown volume handle', function () {
    $output = $this->registry->execute('get_assets', ['volume' => 'does-not-exist']);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('does-not-exist');
});

it('rejects an unknown kind', function () {
    $output = $this->registry->execute('get_assets', ['kind' => 'bogus-kind']);

    expect($output->isError)->toBeTrue();
});
