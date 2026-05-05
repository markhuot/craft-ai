<?php

use markhuot\craftai\tools\GetVolumes;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetVolumes::class);
});

it('returns all volumes', function () {
    Volume::factory()->name('Uploads')->handle('uploads')->create();
    Volume::factory()->name('Images')->handle('images')->create();

    $output = $this->registry->execute('get_volumes', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('uploads');
    expect($handles)->toContain('images');
});

it('exposes id, uid, name, and handle for each volume', function () {
    Volume::factory()->name('Uploads')->handle('uploads')->create();

    $output = $this->registry->execute('get_volumes', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $row = collect($payload)->firstWhere('handle', 'uploads');
    expect($row)->not->toBeNull();
    expect($row)->toHaveKeys(['id', 'uid', 'name', 'handle']);
    expect($row['name'])->toBe('Uploads');
});
