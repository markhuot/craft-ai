<?php

use CraftCms\Cms\Field\PlainText;
use markhuot\craftai\events\DefineFieldNotesEvent;
use markhuot\craftai\tools\GetFields;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertField;
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

it('publishes the span-comment workflow in the ckeditor field notes', function () {
    // craftcms/ckeditor isn't installed in the test environment, so
    // we can't drive the listener through a real field instance.
    // The notes string itself is the contract — assert the literal
    // wrapper attributes appear so a future edit that softens the
    // guidance back into passive voice gets caught here.
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/listeners/CkeditorFieldNotes.php');

    expect($contents)->toContain('craft-ai-comment-mark');
    expect($contents)->toContain('data-craft-ai-comment-id');
    expect($contents)->toContain('leave_comment');
    // Specifically the active-voice "you do it" framing — the field
    // notes have to teach the agent the same workflow leave_comment's
    // own description teaches.
    expect($contents)->toContain('upsert_entry');
});

it('mentions ckeditor in the get_fields tool-level notes', function () {
    seedField('body', 'Body', PlainText::class);

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
        seedField('body', 'Body', PlainText::class);
        $this->registry->execute('get_fields', []);

        expect($seen)->toContain('body');
    } finally {
        Event::off(UpsertField::class, UpsertField::EVENT_DEFINE_FIELD_NOTES, $handler);
    }
});

it('joins multiple subscribers notes with a blank line in the field payload', function () {
    seedField('eventBody', 'Body', PlainText::class);
    // UpsertField::summarize() expects a live Craft FieldInterface, not the
    // Eloquent Field model the seedField helper returns — resolve the saved
    // field back through the Fields service.
    $field = Craft::$app->getFields()->getFieldByHandle('eventBody');

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
    seedField('quietBody', 'Body', PlainText::class);
    $field = Craft::$app->getFields()->getFieldByHandle('quietBody');

    $payload = UpsertField::summarize($field);

    expect($payload)->not->toHaveKey('_notes');
});
