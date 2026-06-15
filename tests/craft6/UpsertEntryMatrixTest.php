<?php

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Entry\Models\EntryType;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\Models\Field;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\Models\FieldLayout;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Str;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;

function seedBlockEntryType(string $name, string $handle, array $fields): EntryType
{
    $elements = array_map(static fn ($field) => [
        'uid' => (string) Str::uuid(),
        'type' => CustomField::class,
        'fieldUid' => $field->uid,
        'required' => false,
    ], $fields);

    $layout = FieldLayout::factory()->withContentTab($elements)->create();

    $entryType = EntryType::factory()->create([
        'name' => $name,
        'handle' => $handle,
        'fieldLayoutId' => $layout->id,
        'hasTitleField' => false,
    ]);

    EntryTypes::refreshEntryTypes();

    return $entryType;
}

function seedMatrixField(string $name, string $handle, array $entryTypes): Matrix
{
    $matrix = Field::factory()->create([
        'name' => $name,
        'handle' => $handle,
        'type' => Matrix::class,
        'settings' => [
            'entryTypes' => array_map(static fn (EntryType $et) => $et->uid, $entryTypes),
            'viewMode' => 'blocks',
        ],
    ]);

    Fields::refreshFields();

    return Fields::getFieldByHandle($handle);
}

beforeEach(function () {
    $bodyField = seedField('body', 'Body', PlainText::class);
    $headingField = seedField('headingText', 'Heading Text', PlainText::class);

    $textBlock = seedBlockEntryType('Text', 'text', [$bodyField]);
    $headingBlock = seedBlockEntryType('Heading', 'heading', [$headingField]);

    $matrix = seedMatrixField('Content Builder', 'contentBuilder', [$textBlock, $headingBlock]);

    seedSection('posts', 'Posts', \CraftCms\Cms\Section\Enums\SectionType::Channel, [$matrix]);

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
});

it('creates new matrix blocks via "new1"/"new2" placeholder keys', function () {
    $output = $this->registry->execute('upsert_entry', [
        'section' => 'posts',
        'title' => 'My Post',
        'fields' => [
            'contentBuilder' => [
                'new1' => ['type' => 'heading', 'fields' => ['headingText' => 'Welcome']],
                'new2' => ['type' => 'text', 'fields' => ['body' => 'Body copy here']],
            ],
        ],
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entry = Entry::find()->id(decode($output)['data']['entry']['id'])->status(null)->one();
    $blocks = $entry->contentBuilder->all();
    expect($blocks)->toHaveCount(2);
    expect($blocks[0]->getType()->handle)->toBe('heading');
    expect((string) $blocks[0]->headingText)->toBe('Welcome');
    expect($blocks[1]->getType()->handle)->toBe('text');
    expect((string) $blocks[1]->body)->toBe('Body copy here');
});

it('updates an existing matrix block by id and adds a new block via "new1"', function () {
    $created = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts',
        'title' => 'Editable',
        'fields' => [
            'contentBuilder' => [
                'new1' => ['type' => 'text', 'fields' => ['body' => 'Original']],
            ],
        ],
    ]))['data']['entry'];

    $entry = Entry::find()->id($created['id'])->status(null)->one();
    $existingId = $entry->contentBuilder->all()[0]->id;

    $update = $this->registry->execute('upsert_entry', [
        'id' => $created['id'],
        'fields' => [
            'contentBuilder' => [
                (string) $existingId => ['type' => 'text', 'fields' => ['body' => 'Updated body']],
                'new1' => ['type' => 'heading', 'fields' => ['headingText' => 'Appended']],
            ],
        ],
    ]);

    expect($update->isError)->toBeFalse($update->text);

    $reloaded = Entry::find()->id($created['id'])->status(null)->one();
    $blocks = $reloaded->contentBuilder->all();
    expect($blocks)->toHaveCount(2);
    expect($blocks[0]->id)->toBe($existingId);
    expect((string) $blocks[0]->body)->toBe('Updated body');
    expect($blocks[1]->getType()->handle)->toBe('heading');
    expect((string) $blocks[1]->headingText)->toBe('Appended');
});

it('describes how to submit matrix blocks (new1/new2 keys) in the upsert_entry tool description', function () {
    $descriptor = $this->registry->describe('upsert_entry');

    expect($descriptor->description)->toContain('Matrix');
    expect($descriptor->description)->toContain('new1');
    expect($descriptor->description)->toContain('settings.entryTypes');
});

it('describes the matrix block submission shape on the fields parameter', function () {
    $descriptor = $this->registry->describe('upsert_entry');
    $fieldsDescription = $descriptor->inputSchema['properties']['fields']['description'] ?? '';

    expect($fieldsDescription)->toContain('new1');
    expect($fieldsDescription)->toContain('Matrix');
});
