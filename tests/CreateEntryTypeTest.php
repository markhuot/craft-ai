<?php

use craft\fieldlayoutelements\TitleField;
use markhuot\craftai\tools\CreateEntryType;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(CreateEntryType::class);
});

it('creates an entry type with a title field in the layout when hasTitleField is true', function () {
    $output = $this->registry->execute('create_entry_type', [
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
    $output = $this->registry->execute('create_entry_type', [
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
