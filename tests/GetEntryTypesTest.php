<?php

use markhuot\craftai\tools\GetEntryTypes;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetEntryTypes::class);
});

it('returns all entry types when no section filter is given', function () {
    Section::factory()->name('Posts')->handle('posts')->create();
    Section::factory()->name('Pages')->handle('pages')->create();

    $output = $this->registry->execute('get_entry_types', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toBeArray();

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('posts');
    expect($handles)->toContain('pages');
});

it('filters entry types by section handle', function () {
    Section::factory()->name('Posts')->handle('posts')->create();
    Section::factory()->name('Pages')->handle('pages')->create();

    $output = $this->registry->execute('get_entry_types', ['section' => 'posts']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('posts');
    expect($handles)->not->toContain('pages');
});

it('filters entry types by section ID', function () {
    $posts = Section::factory()->name('Posts')->handle('posts')->create();
    Section::factory()->name('Pages')->handle('pages')->create();

    $output = $this->registry->execute('get_entry_types', ['section' => $posts->id]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('posts');
    expect($handles)->not->toContain('pages');
});

it('includes a field layout summary in each entry type result', function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $output = $this->registry->execute('get_entry_types', ['section' => 'posts']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveCount(1);

    $row = $payload[0];
    expect($row)->toHaveKeys(['id', 'handle', 'name', 'fieldLayoutId', 'tabs']);
    expect($row['tabs'])->toBeArray();
});

it('returns an error for an unknown section handle', function () {
    $output = $this->registry->execute('get_entry_types', ['section' => 'nope']);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('nope');
});

it('returns an error for an unknown section ID', function () {
    $output = $this->registry->execute('get_entry_types', ['section' => 999999]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});
