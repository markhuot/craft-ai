<?php

use CraftCms\Cms\Entry\Models\EntryType;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\Models\Field;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\Models\FieldLayout;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Str;
use markhuot\craftai\tools\GetFields;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertField;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetFields::class);
    $this->registry->register(UpsertField::class);
});

/**
 * Build a block entry type (title field disabled, like craft-pest's
 * ->hasTitleField(false)) whose field layout contains the given fields.
 */
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

/**
 * Build a Matrix field referencing the given block entry types (by uid).
 */
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

function makeMatrixWithTwoBlockTypes(): Matrix
{
    $bodyField = seedField('body', 'Body', PlainText::class);
    $headingField = seedField('headingText', 'Heading Text', PlainText::class);

    $textBlock = seedBlockEntryType('Text', 'text', [$bodyField]);
    $headingBlock = seedBlockEntryType('Heading', 'heading', [$headingField]);

    return seedMatrixField('Content Builder', 'contentBuilder', [$textBlock, $headingBlock]);
}

it('exposes matrix block types and their sub-fields via get_fields', function () {
    makeMatrixWithTwoBlockTypes();

    $output = $this->registry->execute('get_fields', ['type' => Matrix::class]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data'])->toHaveCount(1);

    $entryTypes = $payload['data'][0]['settings']['entryTypes'];
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

    $entryTypes = $payload['data']['settings']['entryTypes'];
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
