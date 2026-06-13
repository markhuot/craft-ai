<?php

use craft\fields\PlainText;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\Heading;
use craft\fieldlayoutelements\HorizontalRule;
use craft\fieldlayoutelements\LineBreak;
use craft\fieldlayoutelements\Markdown;
use craft\fieldlayoutelements\Template;
use craft\fieldlayoutelements\Tip;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertFieldLayoutElement;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertFieldLayoutElement::class);

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

function layoutElementByUid(EntryType $entryType, string $uid): ?\craft\base\FieldLayoutElement
{
    foreach ($entryType->getFieldLayout()->getTabs() as $tab) {
        foreach ($tab->getElements() as $element) {
            if (($element->uid ?? null) === $uid) {
                return $element;
            }
        }
    }

    return null;
}

it('appends a custom field to the end of the first tab by default', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    expect(layoutFieldHandles($entryType))->toContain('body');
});

it('inserts a custom field before an existing element using element UID', function () {
    ($this->makeEntryType)('article');
    $intro = ($this->makeField)('intro', 'Intro');
    $body = ($this->makeField)('body', 'Body');

    $bodyResult = json_decode($this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ])->text, true)['data'];

    $bodyUid = $bodyResult['tabs'][0]['elements'][0]['uid'];

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $intro->uid,
        'position' => 'before',
        'relativeTo' => $bodyUid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $handles = layoutFieldHandles($entryType);
    expect(array_search('intro', $handles, true))
        ->toBeLessThan(array_search('body', $handles, true));
});

it('inserts a custom field after an existing element using element UID', function () {
    ($this->makeEntryType)('article');
    $intro = ($this->makeField)('intro', 'Intro');
    $body = ($this->makeField)('body', 'Body');

    $introResult = json_decode($this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $intro->uid,
    ])->text, true)['data'];

    $introUid = $introResult['tabs'][0]['elements'][0]['uid'];

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
        'position' => 'after',
        'relativeTo' => $introUid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $handles = layoutFieldHandles($entryType);
    expect(array_search('intro', $handles, true))
        ->toBeLessThan(array_search('body', $handles, true));
});

it('rejects adding the same custom field twice', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ]);

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('already in the layout');
});

it('errors when before/after is used without relativeTo', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
        'position' => 'before',
    ]);

    expect($output->isError)->toBeTrue();
});

it('creates a new tab when an unknown tab name is provided', function () {
    ($this->makeEntryType)('article');
    $seoTitle = ($this->makeField)('seoTitle', 'SEO Title');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $seoTitle->uid,
        'tab' => 'SEO',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $names = array_map(fn ($t) => $t->name, $entryType->getFieldLayout()->getTabs());
    expect($names)->toContain('SEO');
});

it('rejects the field parameter when given a handle instead of a UID', function () {
    ($this->makeEntryType)('article');
    ($this->makeField)('body', 'Body');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => 'body',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('UID');
});

it('errors when type=customField but no field is given', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
    ]);

    expect($output->isError)->toBeTrue();
});

it('errors when neither elementUid nor type is given', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
    ]);

    expect($output->isError)->toBeTrue();
});

it('inserts a heading element', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'heading',
        'headingText' => 'Article details',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof Heading);

    expect($found)->not->toBeNull();
    expect($found->heading)->toBe('Article details');
});

it('inserts a tip element with style and dismissible flag', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'tip',
        'tipText' => 'Be brief.',
        'tipStyle' => 'warning',
        'tipDismissible' => true,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof Tip);

    expect($found)->not->toBeNull();
    expect($found->tip)->toBe('Be brief.');
    expect($found->style)->toBe('warning');
    expect($found->dismissible)->toBeTrue();
});

it('inserts a markdown element with content', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'markdown',
        'markdownContent' => '## Hello',
        'markdownDisplayInPane' => false,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof Markdown);

    expect($found)->not->toBeNull();
    expect($found->content)->toBe('## Hello');
    expect($found->displayInPane)->toBeFalse();
});

it('inserts a line break element', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'lineBreak',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof LineBreak);

    expect($found)->not->toBeNull();
});

it('inserts a horizontal rule element', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'horizontalRule',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof HorizontalRule);

    expect($found)->not->toBeNull();
});

it('inserts a template element with path and mode', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'template',
        'templatePath' => '_partials/preview',
        'templateMode' => 'site',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof Template);

    expect($found)->not->toBeNull();
    expect($found->template)->toBe('_partials/preview');
    expect($found->templateMode)->toBe('site');
});

it('inserts the entry title field and flips hasTitleField', function () {
    $entryType = ($this->makeEntryType)('article');
    expect($entryType->hasTitleField)->toBeFalse();

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'title',
        'label' => 'Headline',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    expect($entryType->hasTitleField)->toBeTrue();

    $found = collect($entryType->getFieldLayout()->getTabs())
        ->flatMap(fn ($t) => $t->getElements())
        ->first(fn ($el) => $el instanceof EntryTitleField);

    expect($found)->not->toBeNull();
    expect($found->label)->toBe('Headline');
});

it('rejects a second title field', function () {
    ($this->makeEntryType)('article');

    $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'title',
    ]);

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'title',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Title field is already');
});

it('returns element UIDs in the response so callers can update or remove them', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ]);

    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    $result = $payload['data'];
    $element = $result['tabs'][0]['elements'][0];

    expect($element['uid'])->toBeString();
    expect($element['type'])->toBe('customField');
    expect($element['fieldUid'])->toBe($body->uid);
});

it('updates a custom field element label and required flag without moving it', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $insert = json_decode($this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ])->text, true)['data'];

    $bodyUid = $insert['tabs'][0]['elements'][0]['uid'];

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $bodyUid,
        'label' => 'Body Copy',
        'required' => true,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    /** @var CustomField $element */
    $element = layoutElementByUid($entryType, $bodyUid);
    expect($element)->not->toBeNull();
    expect($element->label)->toBe('Body Copy');
    expect($element->required)->toBeTrue();
});

it('moves an existing element when position is provided on update', function () {
    ($this->makeEntryType)('article');
    $intro = ($this->makeField)('intro', 'Intro');
    $body = ($this->makeField)('body', 'Body');

    $introInsert = json_decode($this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $intro->uid,
    ])->text, true)['data'];
    $introUid = $introInsert['tabs'][0]['elements'][0]['uid'];

    $bodyInsert = json_decode($this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ])->text, true)['data'];
    $bodyUid = $bodyInsert['tabs'][0]['elements'][1]['uid'];

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $bodyUid,
        'position' => 'before',
        'relativeTo' => $introUid,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    $handles = layoutFieldHandles($entryType);
    expect(array_search('body', $handles, true))
        ->toBeLessThan(array_search('intro', $handles, true));
});

it('updates heading text on an existing heading', function () {
    ($this->makeEntryType)('article');

    $insert = json_decode($this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'heading',
        'headingText' => 'Original',
    ])->text, true)['data'];
    $headingUid = $insert['tabs'][0]['elements'][0]['uid'];

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $headingUid,
        'headingText' => 'Updated',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $entryType = Craft::$app->entries->getEntryTypeByHandle('article');
    /** @var Heading $heading */
    $heading = layoutElementByUid($entryType, $headingUid);
    expect($heading->heading)->toBe('Updated');
});

it('errors when type provided on update does not match existing element', function () {
    ($this->makeEntryType)('article');
    $body = ($this->makeField)('body', 'Body');

    $insert = json_decode($this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'type' => 'customField',
        'field' => $body->uid,
    ])->text, true)['data'];
    $bodyUid = $insert['tabs'][0]['elements'][0]['uid'];

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => $bodyUid,
        'type' => 'heading',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Cannot change');
});

it('errors when elementUid does not match an element in the layout', function () {
    ($this->makeEntryType)('article');

    $output = $this->registry->execute('upsert_field_layout_element', [
        'entryType' => 'article',
        'elementUid' => '00000000-0000-0000-0000-000000000000',
        'headingText' => 'Whatever',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('No element');
});
