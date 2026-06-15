<?php

use markhuot\craftai\tools\GetVolumes;
use markhuot\craftai\tools\ToolRegistry;
use CraftCms\Cms\Asset\Models\Volume;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetVolumes::class);
});

it('returns all volumes', function () {
    Volume::factory()->create(['name' => 'Uploads', 'handle' => 'uploads']);
    Volume::factory()->create(['name' => 'Images', 'handle' => 'images']);

    $output = $this->registry->execute('get_volumes', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('uploads');
    expect($handles)->toContain('images');
});

it('exposes id, uid, name, and handle for each volume', function () {
    Volume::factory()->create(['name' => 'Uploads', 'handle' => 'uploads']);

    $output = $this->registry->execute('get_volumes', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $row = collect($payload['data'])->firstWhere('handle', 'uploads');
    expect($row)->not->toBeNull();
    expect($row)->toHaveKeys(['id', 'uid', 'name', 'handle']);
    expect($row['name'])->toBe('Uploads');
});
