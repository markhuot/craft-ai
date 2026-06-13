<?php

use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Section\Models\Section;
use markhuot\craftai\tools\GetSections;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetSections::class);
});

it('returns all sections when no type filter is given', function () {
    Section::factory()->create(['name' => 'Posts', 'handle' => 'posts', 'type' => SectionType::Channel]);
    Section::factory()->create(['name' => 'Home', 'handle' => 'home', 'type' => SectionType::Single]);

    $output = $this->registry->execute('get_sections', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('posts');
    expect($handles)->toContain('home');
});

it('filters sections by type "channel"', function () {
    Section::factory()->create(['name' => 'Posts', 'handle' => 'posts', 'type' => SectionType::Channel]);
    Section::factory()->create(['name' => 'Home', 'handle' => 'home', 'type' => SectionType::Single]);

    $output = $this->registry->execute('get_sections', ['type' => 'channel']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('posts');
    expect($handles)->not->toContain('home');
});

it('filters sections by type "single"', function () {
    Section::factory()->create(['name' => 'Posts', 'handle' => 'posts', 'type' => SectionType::Channel]);
    Section::factory()->create(['name' => 'Home', 'handle' => 'home', 'type' => SectionType::Single]);

    $output = $this->registry->execute('get_sections', ['type' => 'single']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('home');
    expect($handles)->not->toContain('posts');
});

it('filters sections by type "structure"', function () {
    Section::factory()->create(['name' => 'Tree', 'handle' => 'tree', 'type' => SectionType::Structure]);
    Section::factory()->create(['name' => 'Posts', 'handle' => 'posts', 'type' => SectionType::Channel]);

    $output = $this->registry->execute('get_sections', ['type' => 'structure']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('tree');
    expect($handles)->not->toContain('posts');
});

it('returns an empty array when no sections match the type', function () {
    Section::factory()->create(['name' => 'Posts', 'handle' => 'posts', 'type' => SectionType::Channel]);

    $output = $this->registry->execute('get_sections', ['type' => 'single']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data'])->toBe([]);
    expect($payload)->toHaveKey('_notes');
    expect($payload['_notes'])->toBeString();
});

it('wraps section results with a _notes hint', function () {
    Section::factory()->create(['name' => 'Posts', 'handle' => 'posts', 'type' => SectionType::Channel]);

    $output = $this->registry->execute('get_sections', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
});

it('rejects an invalid type filter', function () {
    $output = $this->registry->execute('get_sections', ['type' => 'invalid']);

    expect($output->isError)->toBeTrue();
});

it('includes enabledSiteHandles on every section', function () {
    Section::factory()->create(['name' => 'Posts', 'handle' => 'posts', 'type' => SectionType::Channel]);

    $output = $this->registry->execute('get_sections', []);
    expect($output->isError)->toBeFalse($output->text);

    $payload = decode($output);
    $posts = collect($payload['data'])->firstWhere('handle', 'posts');
    expect($posts)->toHaveKey('enabledSiteHandles');
    expect($posts['enabledSiteHandles'])->toContain('default');
});
