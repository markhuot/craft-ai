<?php

use craft\fields\PlainText;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpdateFieldLayout;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpdateFieldLayout::class);

    $this->makeField = function (string $handle, string $name): \craft\base\FieldInterface {
        $field = Craft::$app->fields->createField([
            'type' => PlainText::class,
            'handle' => $handle,
            'name' => $name,
        ]);
        Craft::$app->fields->saveField($field);

        return $field;
    };

    $this->makeEntryType = function (string $handle): EntryType {
        $entryType = new EntryType();
        $entryType->name = ucfirst($handle);
        $entryType->handle = $handle;
        Craft::$app->entries->saveEntryType($entryType);

        return $entryType;
    };
});

function layoutFieldHandles(EntryType $entryType): array
{
    $handles = [];
    foreach ($entryType->getFieldLayout()->getTabs() as $tab) {
        foreach ($tab->getElements() as $element) {
            if ($element instanceof CustomField) {
                $handles[] = $element->getField()->handle;
            }
        }
    }

    return $handles;
}

it('appends a field to the end of the first tab by default', function () {
    $entryType = ($this->makeEntryType)('article');
    ($this->makeField)('body', 'Body');

    $output = $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'body',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    expect(layoutFieldHandles($entryType))->toContain('body');
});

it('inserts a field before an existing field', function () {
    $entryType = ($this->makeEntryType)('article');
    ($this->makeField)('intro', 'Intro');
    ($this->makeField)('body', 'Body');

    $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'body',
    ]);

    $output = $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'intro',
        'position' => 'before',
        'relativeTo' => 'body',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $handles = layoutFieldHandles($entryType);
    $intro = array_search('intro', $handles, true);
    $body = array_search('body', $handles, true);
    expect($intro)->not->toBeFalse();
    expect($body)->not->toBeFalse();
    expect($intro)->toBeLessThan($body);
});

it('inserts a field after an existing field', function () {
    ($this->makeEntryType)('article');
    ($this->makeField)('intro', 'Intro');
    ($this->makeField)('body', 'Body');

    $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'intro',
    ]);

    $output = $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'body',
        'position' => 'after',
        'relativeTo' => 'intro',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $handles = layoutFieldHandles($entryType);
    expect(array_search('intro', $handles, true))
        ->toBeLessThan(array_search('body', $handles, true));
});

it('rejects adding a field that is already in the layout', function () {
    ($this->makeEntryType)('article');
    ($this->makeField)('body', 'Body');

    $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'body',
    ]);

    $output = $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'body',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('already in the layout');
});

it('errors when before/after is used without relativeTo', function () {
    ($this->makeEntryType)('article');
    ($this->makeField)('body', 'Body');

    $output = $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'body',
        'position' => 'before',
    ]);

    expect($output->isError)->toBeTrue();
});

it('creates a new tab when an unknown tab name is provided', function () {
    ($this->makeEntryType)('article');
    ($this->makeField)('seoTitle', 'SEO Title');

    $output = $this->registry->execute('update_field_layout', [
        'entryType' => 'article',
        'field' => 'seoTitle',
        'tab' => 'SEO',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $tabs = $entryType->getFieldLayout()->getTabs();
    $names = array_map(fn ($t) => $t->name, $tabs);
    expect($names)->toContain('SEO');
});
