<?php

use craft\elements\Entry;
use craft\fields\PlainText;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\EntryType;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\MatrixField as MatrixFieldFactory;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $bodyField = Field::factory()->name('Body')->handle('body')->type(PlainText::class);
    $headingField = Field::factory()->name('Heading Text')->handle('headingText')->type(PlainText::class);

    $textBlock = EntryType::factory()
        ->name('Text')
        ->handle('text')
        ->hasTitleField(false)
        ->fields($bodyField);

    $headingBlock = EntryType::factory()
        ->name('Heading')
        ->handle('heading')
        ->hasTitleField(false)
        ->fields($headingField);

    $matrix = MatrixFieldFactory::factory()
        ->name('Content Builder')
        ->handle('contentBuilder')
        ->entryTypes($textBlock, $headingBlock)
        ->create();

    Section::factory()
        ->name('Posts')
        ->handle('posts')
        ->fields($matrix)
        ->create();

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

    $entry = Entry::find()->id(decode($output)['entry']['id'])->status(null)->one();
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
    ]))['entry'];

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
