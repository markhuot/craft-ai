<?php

use craft\fields\Matrix;
use craft\fields\PlainText;
use markhuot\craftai\tools\GetFields;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertField;
use markhuot\craftpest\factories\EntryType;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\MatrixField as MatrixFieldFactory;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetFields::class);
    $this->registry->register(UpsertField::class);
});

function makeMatrixWithTwoBlockTypes(): Matrix
{
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

    return MatrixFieldFactory::factory()
        ->name('Content Builder')
        ->handle('contentBuilder')
        ->entryTypes($textBlock, $headingBlock)
        ->create();
}

it('exposes matrix block types and their sub-fields via get_fields', function () {
    makeMatrixWithTwoBlockTypes();

    $output = $this->registry->execute('get_fields', ['type' => Matrix::class]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveCount(1);

    $entryTypes = $payload[0]['settings']['entryTypes'];
    expect($entryTypes)->toHaveCount(2);

    $byHandle = collect($entryTypes)->keyBy('handle');
    expect($byHandle->keys()->all())->toEqualCanonicalizing(['text', 'heading']);

    $text = $byHandle->get('text');
    expect($text['name'])->toBe('Text');
    expect($text)->toHaveKeys(['uid', 'id', 'fieldLayoutId', 'tabs']);

    $textElements = $text['tabs'][0]['elements'];
    $textHandles = array_filter(array_column($textElements, 'fieldHandle'));
    expect(array_values($textHandles))->toContain('body');

    $heading = $byHandle->get('heading');
    $headingElements = $heading['tabs'][0]['elements'];
    $headingHandles = array_filter(array_column($headingElements, 'fieldHandle'));
    expect(array_values($headingHandles))->toContain('headingText');
});

it('exposes matrix block-type schema via upsert_field on update', function () {
    $matrix = makeMatrixWithTwoBlockTypes();

    $output = $this->registry->execute('upsert_field', [
        'id' => $matrix->id,
        'instructions' => 'Pick a block to add',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $entryTypes = $payload['settings']['entryTypes'];
    expect($entryTypes)->toHaveCount(2);

    $byHandle = collect($entryTypes)->keyBy('handle');
    expect($byHandle->get('text')['name'])->toBe('Text');
    expect($byHandle->get('heading')['name'])->toBe('Heading');
});

it('describes the matrix block schema location in the get_fields tool description', function () {
    $descriptor = $this->registry->describe('get_fields');

    expect($descriptor->description)->toContain('Matrix');
    expect($descriptor->description)->toContain('settings.entryTypes');
});

it('describes how to update matrix block schemas via the upsert_field tool description', function () {
    $descriptor = $this->registry->describe('upsert_field');

    expect($descriptor->description)->toContain('Matrix');
    expect($descriptor->description)->toContain('entryTypes');
});
