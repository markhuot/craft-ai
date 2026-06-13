<?php

use craft\fieldlayoutelements\TitleField;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntryType;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntryType::class);
});

it('creates an entry type with a title field in the layout when hasTitleField is true', function () {
    $output = $this->registry->execute('upsert_entry_type', [
        'name' => 'Article',
        'handle' => 'article',
        'hasTitleField' => true,
    ]);

    expect($output->isError)->toBeFalse();

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    expect($entryType)->not->toBeNull();
    expect($entryType->hasTitleField)->toBeTrue();

    $elements = $entryType->getFieldLayout()->getTabs()[0]->getElements();
    $hasTitle = collect($elements)->contains(fn ($e) => $e instanceof TitleField);
    expect($hasTitle)->toBeTrue();
});

it('omits the title field from the layout when hasTitleField is false', function () {
    $output = $this->registry->execute('upsert_entry_type', [
        'name' => 'Untitled',
        'handle' => 'untitled',
        'hasTitleField' => false,
        'titleFormat' => '{id}',
    ]);

    expect($output->isError)->toBeFalse();

    $entryType = Craft::$app->entries->getEntryTypeByHandle('untitled');
    expect($entryType)->not->toBeNull();
    expect($entryType->hasTitleField)->toBeFalse();

    $elements = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($tab) => $tab->getElements());
    $hasTitle = $elements->contains(fn ($e) => $e instanceof TitleField);
    expect($hasTitle)->toBeFalse();
});

it('defaults hasTitleField to true on create when omitted', function () {
    $output = $this->registry->execute('upsert_entry_type', [
        'name' => 'Post',
        'handle' => 'post',
    ]);

    expect($output->isError)->toBeFalse();

    $entryType = Craft::$app->entries->getEntryTypeByHandle('post');
    expect($entryType->hasTitleField)->toBeTrue();
});

it('updates an existing entry type without changing untouched fields', function () {
    $this->registry->execute('upsert_entry_type', [
        'name' => 'Article',
        'handle' => 'article',
        'hasTitleField' => false,
        'titleFormat' => '{id}',
    ]);

    $original = Craft::$app->entries->getEntryTypeByHandle('article');
    expect($original->hasTitleField)->toBeFalse();

    $output = $this->registry->execute('upsert_entry_type', [
        'id' => $original->id,
        'name' => 'Updated Article',
    ]);

    expect($output->isError)->toBeFalse();

    $updated = Craft::$app->entries->getEntryTypeById($original->id);
    expect($updated->name)->toBe('Updated Article');
    expect($updated->handle)->toBe('article');
    expect($updated->hasTitleField)->toBeFalse();
});

it('returns an error when creating without required fields', function () {
    $output = $this->registry->execute('upsert_entry_type', []);

    expect($output->isError)->toBeTrue();
});
