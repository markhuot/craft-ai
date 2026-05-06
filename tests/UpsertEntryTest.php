<?php

use craft\elements\Entry;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
});

it('creates an entry with a title', function () {
    $output = $this->registry->execute('upsert_entry', ['section' => 'posts', 'title' => 'Hello World']);

    expect($output->isError)->toBeFalse();
    $result = decode($output)['entry'];
    expect($result['title'])->toBe('Hello World');

    $entry = Entry::find()->id($result['id'])->status(null)->one();
    expect($entry->title)->toBe('Hello World');
});

it('creates an entry with a custom slug', function () {
    $output = $this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'My Article', 'slug' => 'my-custom-slug',
    ]);

    expect(decode($output)['entry']['slug'])->toBe('my-custom-slug');
});

it('creates a disabled entry when enabled is false', function () {
    $output = $this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Draft Post', 'enabled' => false,
    ]);

    $entry = Entry::find()->id(decode($output)['entry']['id'])->status(null)->one();
    expect($entry->enabled)->toBeFalse();
});

it('returns an error for an unknown section', function () {
    $result = $this->registry->execute('upsert_entry', ['section' => 'nope', 'title' => 'Whatever']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('nope');
});

it('returns an error for an unknown entry type', function () {
    $result = $this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Whatever', 'type' => 'nonsense',
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('nonsense');
});

it('creates an entry with a specific entry type handle', function () {
    $section = Craft::$app->entries->getSectionByHandle('posts');
    $entryType = $section->getEntryTypes()[0];

    $output = $this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Typed', 'type' => $entryType->handle,
    ]);

    $result = decode($output)['entry'];
    expect($result['title'])->toBe('Typed');
    expect($result['typeId'])->toBe($entryType->id);
});

it('creates an entry with a postDate', function () {
    $output = $this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Dated', 'postDate' => '2024-01-15 10:30:00',
    ]);

    $entry = Entry::find()->id(decode($output)['entry']['id'])->status(null)->one();
    expect($entry->postDate->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:30:00');
});

it('rejects titles longer than 255 characters', function () {
    $result = $this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => str_repeat('a', 256),
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('Validation failed');
});

it('rejects an unknown site handle', function () {
    $result = $this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Hi', 'site' => 'klingon',
    ]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('klingon');
});

it('exposes section and type as string-or-integer in the JSON schema', function () {
    $descriptor = $this->registry->describe('upsert_entry');
    $schema = $descriptor->inputSchema;

    expect($schema['properties']['section']['oneOf'])->toBe([['type' => 'string'], ['type' => 'integer']]);
    expect($schema['properties']['type']['oneOf'])->toBe([['type' => 'string'], ['type' => 'integer']]);
});

it('updates an existing entry by id', function () {
    $created = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Original',
    ]))['entry'];

    $output = $this->registry->execute('upsert_entry', [
        'id' => $created['id'], 'title' => 'Updated',
    ]);

    expect($output->isError)->toBeFalse();
    expect(decode($output)['entry']['id'])->toBe($created['id']);

    $entry = Entry::find()->id($created['id'])->status(null)->one();
    expect($entry->title)->toBe('Updated');
});

it('leaves untouched fields alone on update', function () {
    $created = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Keep Slug', 'slug' => 'keep-slug',
    ]))['entry'];

    $this->registry->execute('upsert_entry', [
        'id' => $created['id'], 'title' => 'New Title',
    ]);

    $entry = Entry::find()->id($created['id'])->status(null)->one();
    expect($entry->title)->toBe('New Title');
    expect($entry->slug)->toBe('keep-slug');
});

it('returns an error for an unknown entry id', function () {
    $result = $this->registry->execute('upsert_entry', ['id' => 999999, 'title' => 'Nope']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('requires section and title when no id is given', function () {
    $result = $this->registry->execute('upsert_entry', []);

    expect($result->isError)->toBeTrue();
});

it('collects missing section and title into a single error response', function () {
    $result = $this->registry->execute('upsert_entry', []);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('Section');
    expect($result->text)->toContain('Title');
});

it('skips create-only required rules when updating', function () {
    $created = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Original',
    ]))['entry'];

    $output = $this->registry->execute('upsert_entry', ['id' => $created['id']]);

    expect($output->isError)->toBeFalse();
});

it('binds a section by numeric ID', function () {
    $section = Craft::$app->entries->getSectionByHandle('posts');

    $output = $this->registry->execute('upsert_entry', [
        'section' => $section->id,
        'title' => 'By ID',
    ]);

    expect($output->isError)->toBeFalse();
    expect(decode($output)['entry']['title'])->toBe('By ID');
});

it('wraps the response with a notes prompt to call open_preview when the entry has a URL', function () {
    $output = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Previewable',
    ]));

    expect($output)->toHaveKeys(['notes', 'entry']);
    expect($output['notes'])->toContain('open_preview');
    expect($output['notes'])->toContain($output['entry']['url']);
});

it('returns the entry without a notes wrapper when the section has no front-end URLs', function () {
    Section::factory()->name('Hidden')->handle('hidden')->hasUrls(false)->create();

    $output = decode($this->registry->execute('upsert_entry', [
        'section' => 'hidden', 'title' => 'Invisible',
    ]));

    expect($output)->not->toHaveKey('notes');
    expect($output)->not->toHaveKey('entry');
    expect($output['title'])->toBe('Invisible');
    expect($output['url'])->toBeNull();
});

it('emits a generic Entry saved. note for MCP clients without referencing open_preview', function () {
    /** @var ToolContext $context */
    $context = Craft::$container->get(ToolContext::class);
    $context->begin(null, null, ClientType::MCP);

    $output = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'For MCP',
    ]));

    expect($output)->toHaveKeys(['notes', 'entry']);
    expect($output['notes'])->toBe('Entry saved.');
    expect($output['notes'])->not->toContain('open_preview');
    expect($output['entry']['title'])->toBe('For MCP');
});
