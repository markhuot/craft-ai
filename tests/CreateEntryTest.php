<?php

use craft\elements\Entry;
use markhuot\craftai\tools\CreateEntry;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(CreateEntry::class);
});

function decode(ToolOutput $output): array
{
    return json_decode($output->text, true);
}

it('creates an entry with a title', function () {
    $output = $this->registry->execute('create_entry', ['section' => 'posts', 'title' => 'Hello World']);

    expect($output->isError)->toBeFalse();
    $result = decode($output);
    expect($result['title'])->toBe('Hello World');

    $entry = Entry::find()->id($result['id'])->status(null)->one();
    expect($entry->title)->toBe('Hello World');
});

it('creates an entry with a custom slug', function () {
    $output = $this->registry->execute('create_entry', [
        'section' => 'posts', 'title' => 'My Article', 'slug' => 'my-custom-slug',
    ]);

    expect(decode($output)['slug'])->toBe('my-custom-slug');
});

it('creates a disabled entry when enabled is false', function () {
    $output = $this->registry->execute('create_entry', [
        'section' => 'posts', 'title' => 'Draft Post', 'enabled' => false,
    ]);

    $entry = Entry::find()->id(decode($output)['id'])->status(null)->one();
    expect($entry->enabled)->toBeFalse();
});

it('returns an error for an unknown section', function () {
    $result = $this->registry->execute('create_entry', ['section' => 'nope', 'title' => 'Whatever']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('nope');
});

it('returns an error for an unknown entry type', function () {
    $result = $this->registry->execute('create_entry', [
        'section' => 'posts', 'title' => 'Whatever', 'type' => 'nonsense',
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('nonsense');
});

it('creates an entry with a specific entry type handle', function () {
    $section = Craft::$app->entries->getSectionByHandle('posts');
    $entryType = $section->getEntryTypes()[0];

    $output = $this->registry->execute('create_entry', [
        'section' => 'posts', 'title' => 'Typed', 'type' => $entryType->handle,
    ]);

    $result = decode($output);
    expect($result['title'])->toBe('Typed');
    expect($result['typeId'])->toBe($entryType->id);
});

it('creates an entry with a postDate', function () {
    $output = $this->registry->execute('create_entry', [
        'section' => 'posts', 'title' => 'Dated', 'postDate' => '2024-01-15 10:30:00',
    ]);

    $entry = Entry::find()->id(decode($output)['id'])->status(null)->one();
    expect($entry->postDate->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:30:00');
});

it('rejects titles longer than 255 characters', function () {
    $result = $this->registry->execute('create_entry', [
        'section' => 'posts', 'title' => str_repeat('a', 256),
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('Validation failed');
});

it('rejects an unknown site handle', function () {
    $result = $this->registry->execute('create_entry', [
        'section' => 'posts', 'title' => 'Hi', 'site' => 'klingon',
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('klingon');
});

it('exposes section and type as string-or-integer in the JSON schema', function () {
    $descriptor = $this->registry->describe('create_entry');
    $schema = $descriptor->inputSchema;

    expect($schema['properties']['section']['oneOf'])->toBe([['type' => 'string'], ['type' => 'integer']]);
    expect($schema['properties']['type']['oneOf'])->toBe([['type' => 'string'], ['type' => 'integer']]);
});

it('binds a section by numeric ID', function () {
    $section = Craft::$app->entries->getSectionByHandle('posts');

    $output = $this->registry->execute('create_entry', [
        'section' => $section->id,
        'title' => 'By ID',
    ]);

    expect($output->isError)->toBeFalse();
    expect(decode($output)['title'])->toBe('By ID');
});
