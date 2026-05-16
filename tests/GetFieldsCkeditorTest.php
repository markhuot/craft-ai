<?php

use craft\fields\PlainText;
use markhuot\craftai\events\DefineFieldNotesEvent;
use markhuot\craftai\tools\GetFields;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertField;
use markhuot\craftpest\factories\Field;
use yii\base\Event;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetFields::class);
    $this->registry->register(UpsertField::class);
});

it('mentions ckeditor and nested entries in the get_fields tool description', function () {
    $descriptor = $this->registry->describe('get_fields');

    expect($descriptor->description)->toContain('CKEditor');
    expect($descriptor->description)->toContain('nested');
});

it('mentions ckeditor in the upsert_field tool description', function () {
    $descriptor = $this->registry->describe('upsert_field');

    expect($descriptor->description)->toContain('CKEditor');
});

it('mentions ckeditor in the get_fields tool-level notes', function () {
    Field::factory()->name('Body')->handle('body')->type(PlainText::class)->create();

    $output = $this->registry->execute('get_fields', []);
    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);

    expect($payload['_notes'])->toContain('CKEditor');
});

it('fires the define-field-notes event for every summarized field', function () {
    $seen = [];
    $handler = function (DefineFieldNotesEvent $event) use (&$seen): void {
        $seen[] = $event->field->handle;
    };
    Event::on(UpsertField::class, UpsertField::EVENT_DEFINE_FIELD_NOTES, $handler);

    try {
        Field::factory()->name('Body')->handle('body')->type(PlainText::class)->create();
        $this->registry->execute('get_fields', []);

        expect($seen)->toContain('body');
    } finally {
        Event::off(UpsertField::class, UpsertField::EVENT_DEFINE_FIELD_NOTES, $handler);
    }
});

it('joins multiple subscribers notes with a blank line in the field payload', function () {
    $field = Field::factory()->name('Body')->handle('eventBody')->type(PlainText::class)->create();

    $first = function (DefineFieldNotesEvent $event): void {
        if ($event->field->handle === 'eventBody') {
            $event->notes[] = 'first note';
        }
    };
    $second = function (DefineFieldNotesEvent $event): void {
        if ($event->field->handle === 'eventBody') {
            $event->notes[] = 'second note';
        }
    };
    Event::on(UpsertField::class, UpsertField::EVENT_DEFINE_FIELD_NOTES, $first);
    Event::on(UpsertField::class, UpsertField::EVENT_DEFINE_FIELD_NOTES, $second);

    try {
        $payload = UpsertField::summarize($field);
        expect($payload['_notes'])->toBe("first note\n\nsecond note");
    } finally {
        Event::off(UpsertField::class, UpsertField::EVENT_DEFINE_FIELD_NOTES, $first);
        Event::off(UpsertField::class, UpsertField::EVENT_DEFINE_FIELD_NOTES, $second);
    }
});

it('omits the notes key when no subscribers contribute a note', function () {
    $field = Field::factory()->name('Body')->handle('quietBody')->type(PlainText::class)->create();

    $payload = UpsertField::summarize($field);

    expect($payload)->not->toHaveKey('_notes');
});
