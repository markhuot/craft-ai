<?php

use craft\fields\Assets;
use craft\fields\PlainText;
use markhuot\craftai\tools\CreateField;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(CreateField::class);
});

it('creates a plain text field by FQCN', function () {
    $output = $this->registry->execute('create_field', [
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
    $output = $this->registry->execute('create_field', [
        'name' => 'Hero Image',
        'handle' => 'heroImage',
        'type' => Assets::class,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $field = Craft::$app->fields->getFieldByHandle('heroImage');
    expect($field)->toBeInstanceOf(Assets::class);
});

it('rejects an unknown field type class', function () {
    $output = $this->registry->execute('create_field', [
        'name' => 'Bogus',
        'handle' => 'bogus',
        'type' => 'App\\Made\\Up\\NotARealField',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('not an installed field type');
});

it('rejects a duplicate handle in the global field context', function () {
    $first = $this->registry->execute('create_field', [
        'name' => 'Body',
        'handle' => 'body',
        'type' => PlainText::class,
    ]);
    expect($first->isError)->toBeFalse($first->text);

    $second = $this->registry->execute('create_field', [
        'name' => 'Body Again',
        'handle' => 'body',
        'type' => PlainText::class,
    ]);
    expect($second->isError)->toBeTrue();
});
