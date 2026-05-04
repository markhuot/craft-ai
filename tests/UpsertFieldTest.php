<?php

use craft\fields\Assets;
use craft\fields\PlainText;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertField;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertField::class);
});

it('creates a plain text field by FQCN', function () {
    $output = $this->registry->execute('upsert_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $field = Craft::$app->fields->getFieldByHandle('body');
    expect($field)->not->toBeNull();
    expect($field)->toBeInstanceOf(PlainText::class);
    expect($field->name)->toBe('Body');
});

it('creates an assets field by FQCN', function () {
    $output = $this->registry->execute('upsert_field', [
        'name' => 'Hero Image',
        'handle' => 'heroImage',
        'type' => Assets::class,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $field = Craft::$app->fields->getFieldByHandle('heroImage');
    expect($field)->toBeInstanceOf(Assets::class);
});

it('rejects an unknown field type class', function () {
    $output = $this->registry->execute('upsert_field', [
        'name' => 'Bogus',
        'handle' => 'bogus',
        'type' => 'App\\Made\\Up\\NotARealField',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('not an installed field type');
});

it('rejects a duplicate handle in the global field context', function () {
    $first = $this->registry->execute('upsert_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
    ]);
    expect($first->isError)->toBeFalse($first->text);

    $second = $this->registry->execute('upsert_field', [
        'name' => 'Body Again',
        'handle' => 'body',
        'type' => PlainText::class,
    ]);
    expect($second->isError)->toBeTrue();
});

it('updates an existing field by handle', function () {
    $create = $this->registry->execute('upsert_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
    ]);
    expect($create->isError)->toBeFalse($create->text);

    $update = $this->registry->execute('upsert_field', [
        'id' => 'body',
        'name' => 'Body Copy',
        'instructions' => 'Main article body',
    ]);
    expect($update->isError)->toBeFalse($update->text);

    $field = Craft::$app->fields->getFieldByHandle('body');
    expect($field)->toBeInstanceOf(PlainText::class);
    expect($field->name)->toBe('Body Copy');
    expect($field->instructions)->toBe('Main article body');
});

it('updates an existing field by id', function () {
    $create = $this->registry->execute('upsert_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
    ]);
    expect($create->isError)->toBeFalse($create->text);

    $field = Craft::$app->fields->getFieldByHandle('body');

    $update = $this->registry->execute('upsert_field', [
        'id' => $field->id,
        'handle' => 'bodyText',
    ]);
    expect($update->isError)->toBeFalse($update->text);

    expect(Craft::$app->fields->getFieldByHandle('bodyText'))->not->toBeNull();
});

it('applies field-type settings on create', function () {
    $output = $this->registry->execute('upsert_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
        'settings' => ['charLimit' => 250, 'multiline' => true],
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $field = Craft::$app->fields->getFieldByHandle('body');
    expect($field)->toBeInstanceOf(PlainText::class);
    expect($field->charLimit)->toBe(250);
    expect($field->multiline)->toBeTrue();
});

it('merges settings on update, preserving existing keys', function () {
    $create = $this->registry->execute('upsert_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
        'settings' => ['charLimit' => 250, 'multiline' => true],
    ]);
    expect($create->isError)->toBeFalse($create->text);

    $update = $this->registry->execute('upsert_field', [
        'id' => 'body',
        'settings' => ['charLimit' => 500],
    ]);
    expect($update->isError)->toBeFalse($update->text);

    $field = Craft::$app->fields->getFieldByHandle('body');
    expect($field->charLimit)->toBe(500);
    expect($field->multiline)->toBeTrue();
});

it('returns settings validation errors so they can be corrected', function () {
    $output = $this->registry->execute('upsert_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
        'settings' => ['charLimit' => -5, 'initialRows' => 0],
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Could not save field');
});

it('rejects an unknown id', function () {
    $output = $this->registry->execute('upsert_field', [
        'id' => 'doesNotExist',
        'name' => 'X',
    ]);

    expect($output->isError)->toBeTrue();
});
