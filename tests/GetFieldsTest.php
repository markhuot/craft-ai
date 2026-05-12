<?php

use craft\fields\Number;
use craft\fields\PlainText;
use markhuot\craftai\tools\GetFields;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Field;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetFields::class);
});

it('returns all fields when no type filter is given', function () {
    Field::factory()->name('Body')->handle('body')->type(PlainText::class)->create();
    Field::factory()->name('Score')->handle('score')->type(Number::class)->create();

    $output = $this->registry->execute('get_fields', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('body');
    expect($handles)->toContain('score');
});

it('filters fields by type FQCN', function () {
    Field::factory()->name('Body')->handle('body')->type(PlainText::class)->create();
    Field::factory()->name('Score')->handle('score')->type(Number::class)->create();

    $output = $this->registry->execute('get_fields', ['type' => PlainText::class]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('body');
    expect($handles)->not->toContain('score');
});

it('returns an empty array when no fields match the type', function () {
    Field::factory()->name('Body')->handle('body')->type(PlainText::class)->create();

    $output = $this->registry->execute('get_fields', ['type' => Number::class]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data'])->toBe([]);
    expect($payload['_notes'])->toBeString()->not->toBe('');
});

it('rejects an unknown field type', function () {
    $output = $this->registry->execute('get_fields', ['type' => 'not\\a\\real\\FieldType']);

    expect($output->isError)->toBeTrue();
});

it('exposes the upsert-shaped payload for each field', function () {
    Field::factory()->name('Body')->handle('body')->type(PlainText::class)->create();

    $output = $this->registry->execute('get_fields', ['type' => PlainText::class]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data'])->toHaveCount(1);

    expect($payload['data'][0])->toHaveKeys([
        'id',
        'uid',
        'name',
        'handle',
        'type',
        'instructions',
        'searchable',
        'translationMethod',
        'translationKeyFormat',
        'settings',
    ]);
    expect($payload['data'][0]['type'])->toBe(PlainText::class);
    expect($payload['data'][0]['handle'])->toBe('body');
});
