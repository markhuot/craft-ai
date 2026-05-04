<?php

use markhuot\craftai\tools\GetSections;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetSections::class);
});

it('returns all sections when no type filter is given', function () {
    Section::factory()->name('Posts')->handle('posts')->type('channel')->create();
    Section::factory()->name('Home')->handle('home')->type('single')->create();

    $output = $this->registry->execute('get_sections', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('posts');
    expect($handles)->toContain('home');
});

it('filters sections by type "channel"', function () {
    Section::factory()->name('Posts')->handle('posts')->type('channel')->create();
    Section::factory()->name('Home')->handle('home')->type('single')->create();

    $output = $this->registry->execute('get_sections', ['type' => 'channel']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('posts');
    expect($handles)->not->toContain('home');
});

it('filters sections by type "single"', function () {
    Section::factory()->name('Posts')->handle('posts')->type('channel')->create();
    Section::factory()->name('Home')->handle('home')->type('single')->create();

    $output = $this->registry->execute('get_sections', ['type' => 'single']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('home');
    expect($handles)->not->toContain('posts');
});

it('filters sections by type "structure"', function () {
    Section::factory()->name('Tree')->handle('tree')->type('structure')->create();
    Section::factory()->name('Posts')->handle('posts')->type('channel')->create();

    $output = $this->registry->execute('get_sections', ['type' => 'structure']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload, 'handle');
    expect($handles)->toContain('tree');
    expect($handles)->not->toContain('posts');
});

it('returns an empty array when no sections match the type', function () {
    Section::factory()->name('Posts')->handle('posts')->type('channel')->create();

    $output = $this->registry->execute('get_sections', ['type' => 'single']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toBe([]);
});

it('rejects an invalid type filter', function () {
    $output = $this->registry->execute('get_sections', ['type' => 'invalid']);

    expect($output->isError)->toBeTrue();
});
