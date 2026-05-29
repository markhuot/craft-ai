<?php

use craft\base\Element;
use craft\events\DefineHtmlEvent;
use markhuot\craftai\models\Command;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();
});

it('adds a Compare button to the entry edit action buttons', function () {
    $entry = \markhuot\craftpest\factories\Entry::factory()->section('posts')->title('Has Revisions')->create();

    // EVENT_DEFINE_ADDITIONAL_BUTTONS is the action-buttons row next to Preview.
    $event = new DefineHtmlEvent(['html' => '<!-- preview button -->']);
    $entry->trigger(Element::EVENT_DEFINE_ADDITIONAL_BUTTONS, $event);

    // Existing button HTML is preserved and our button is appended.
    expect($event->html)->toContain('<!-- preview button -->');
    expect($event->html)->toContain('Compare…');
    expect($event->html)->toContain('ai/compare');
    expect($event->html)->toContain('entryId='.$entry->id);
    // It's a plain action button, not the old full-width sidebar field.
    expect($event->html)->not->toContain('fullwidth');
});

it('does not add the button to a revision view', function () {
    $entry = \markhuot\craftpest\factories\Entry::factory()->section('posts')->title('Rev')->create();
    $revId = Craft::$app->getRevisions()->createRevision($entry, null, null, [], force: true);
    $revision = \craft\elements\Entry::find()->revisions(true)->id($revId)->status(null)->one();

    $event = new DefineHtmlEvent(['html' => '']);
    $revision->trigger(Element::EVENT_DEFINE_ADDITIONAL_BUTTONS, $event);

    expect($event->html)->not->toContain('Compare');
});

it('seeds a /compare slash command pointing at the diff + artifact tools', function () {
    $compare = collect(Command::defaults())->firstWhere('name', 'compare');

    expect($compare)->not->toBeNull();
    expect($compare['enabled'])->toBeTrue();
    expect($compare['prompt'])->toContain('diff_revisions');
    expect($compare['prompt'])->toContain('render_artifact');
    expect($compare['prompt'])->toContain('{args}');
});
