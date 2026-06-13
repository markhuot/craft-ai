<?php

use craft\fields\PlainText;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\Heading;
use craft\fieldlayoutelements\Tip;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use markhuot\craftai\tools\RemoveFieldLayoutElement;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertFieldLayoutElement;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertFieldLayoutElement::class);
    $this->registry->register(RemoveFieldLayoutElement::class);

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
        $entryType->hasTitleField = false;
        $entryType->titleFormat = '{id}';
        Craft::$app->entries->saveEntryType($entryType);

        return $entryType;
    };

    $this->insert = function (array $args): array {
        $output = $this->registry->execute('upsert_field_layout_element', $args);
        expect($output->isError)->toBeFalse($output->text);

        return json_decode($output->text, true);
    };

    $this->lastInsertedUid = function (array $args): string {
        $result = ($this->insert)($args);
        $tabs = $result['data']['tabs'];
        $elements = $tabs[count($tabs) - 1]['elements'];

        return $elements[count($elements) - 1]['uid'];
    };
});

it('removes a custom field element by UID', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $bodyUid = ($this->lastInsertedUid)([
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ]);

    $output = $this->registry->execute('remove_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $bodyUid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $remaining = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->filter(fn ($el) => $el instanceof CustomField);

    expect($remaining)->toBeEmpty();
});

it('removes a heading element', function () {
    ($this->makeEntryType)('article');

    $headingUid = ($this->lastInsertedUid)([
        'entryType' => 'article',
        'type' => 'heading',
        'headingText' => 'Section',
    ]);

    $output = $this->registry->execute('remove_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $headingUid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof Heading);

    expect($found)->toBeNull();
});

it('removes a tip element', function () {
    ($this->makeEntryType)('article');

    $tipUid = ($this->lastInsertedUid)([
        'entryType' => 'article',
        'type' => 'tip',
        'tipText' => 'Heads up.',
    ]);

    $output = $this->registry->execute('remove_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $tipUid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof Tip);

    expect($found)->toBeNull();
});

it('removes the title field and flips hasTitleField to false', function () {
    ($this->makeEntryType)('article');

    $titleUid = ($this->lastInsertedUid)([
        'entryType' => 'article',
        'type' => 'title',
    ]);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    expect($entryType->hasTitleField)->toBeTrue();

    $output = $this->registry->execute('remove_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $titleUid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    expect($entryType->hasTitleField)->toBeFalse();

    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof EntryTitleField);

    expect($found)->toBeNull();
});

it('errors when elementUid does not match any element in the layout', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('remove_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => '00000000-0000-0000-0000-000000000000',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('No element');
});

it('returns the removed element details and the resulting layout', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $bodyUid = ($this->lastInsertedUid)([
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ]);

    $output = $this->registry->execute('remove_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $bodyUid,
    ]);

    $result = json_decode($output->text, true);

    expect($result)->toHaveKey('_notes');
    expect($result)->toHaveKey('data');
    expect($result['data'])->toHaveKey('removedElement');
    expect($result['data']['removedElement']['uid'])->toBe($bodyUid);
    expect($result['data']['removedElement']['type'])->toBe('customField');
    expect($result['data'])->toHaveKey('layout');
    expect($result['data']['layout']['tabs'][0]['elements'])->toBeEmpty();
});
