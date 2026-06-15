<?php

use craft\base\Element;
use craft\events\DefineHtmlEvent;
use markhuot\craftai\models\Command;

beforeEach(function () {
    $section = seedSection('posts', 'Posts');
    // The native Section factory randomizes enableVersioning (faker boolean),
    // and the Compare button is gated on it via Entry::hasRevisions(). Pin it on
    // so the button assertions are deterministic.
    \CraftCms\Cms\Section\Models\Section::query()
        ->where('id', $section->id)
        ->update(['enableVersioning' => true]);

    // hasRevisions() (which gates the Compare button) reads enableVersioning
    // off the Section returned by the memoized Sections service. The raw DB
    // update above bypasses that memo, so whether getSection() sees the new
    // `true` or the factory's faker-random value is nondeterministic. Reset
    // the memo so the service re-reads the updated row.
    \CraftCms\Cms\Support\Facades\Sections::refreshSections();
});

it('adds a Compare button to the entry edit action buttons', function () {
    $entry = seedEntry('posts', ['title' => 'Has Revisions']);

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
    $entry = seedEntry('posts', ['title' => 'Rev']);
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
