<?php

use craft\elements\Entry;
use markhuot\craftai\tools\CreateEntry;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();
});

it('creates an entry with a title', function () {
    $tool = new CreateEntry();
    $result = $tool(section: 'posts', title: 'Hello World');

    expect($result)->toBeArray();
    expect($result['title'])->toBe('Hello World');

    $entry = Entry::find()->id($result['id'])->status(null)->one();
    expect($entry)->not->toBeNull();
    expect($entry->title)->toBe('Hello World');
});

it('creates an entry with a custom slug', function () {
    $tool = new CreateEntry();
    $result = $tool(section: 'posts', title: 'My Article', slug: 'my-custom-slug');

    expect($result['slug'])->toBe('my-custom-slug');
});

it('creates a disabled entry when enabled is false', function () {
    $tool = new CreateEntry();
    $result = $tool(section: 'posts', title: 'Draft Post', enabled: false);

    $entry = Entry::find()->id($result['id'])->status(null)->one();
    expect($entry->enabled)->toBeFalse();
});

it('returns an error for an unknown section', function () {
    $registry = new ToolRegistry();
    $registry->register(CreateEntry::class);

    $result = $registry->execute('create_entry', ['section' => 'nope', 'title' => 'Whatever']);

    expect($result)->toBeInstanceOf(ToolOutput::class);
    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('nope');
});

it('returns an error for an unknown entry type', function () {
    $registry = new ToolRegistry();
    $registry->register(CreateEntry::class);

    $result = $registry->execute('create_entry', [
        'section' => 'posts',
        'title' => 'Whatever',
        'type' => 'nonsense',
    ]);

    expect($result)->toBeInstanceOf(ToolOutput::class);
    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('nonsense');
});

it('creates an entry with a specific entry type handle', function () {
    $section = Craft::$app->entries->getSectionByHandle('posts');
    $entryType = $section->getEntryTypes()[0];

    $tool = new CreateEntry();
    $result = $tool(section: 'posts', title: 'Typed', type: $entryType->handle);

    expect($result['title'])->toBe('Typed');
    expect($result['typeId'])->toBe($entryType->id);
});

it('creates an entry with a postDate', function () {
    $tool = new CreateEntry();
    $result = $tool(section: 'posts', title: 'Dated', postDate: '2024-01-15 10:30:00');

    $entry = Entry::find()->id($result['id'])->status(null)->one();
    expect($entry->postDate->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:30:00');
});

it('rejects titles longer than 255 characters', function () {
    $registry = new ToolRegistry();
    $registry->register(CreateEntry::class);

    $result = $registry->execute('create_entry', [
        'section' => 'posts',
        'title' => str_repeat('a', 256),
    ]);

    expect($result)->toBeInstanceOf(ToolOutput::class);
    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('Validation failed');
});

it('rejects an unknown site handle', function () {
    $registry = new ToolRegistry();
    $registry->register(CreateEntry::class);

    $result = $registry->execute('create_entry', [
        'section' => 'posts',
        'title' => 'Hi',
        'site' => 'klingon',
    ]);

    expect($result)->toBeInstanceOf(ToolOutput::class);
    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('klingon');
});
