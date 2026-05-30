<?php

use craft\events\DefineElementEditorHtmlEvent;
use markhuot\craftai\listeners\InjectElementContext;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();
});

/**
 * Pull the JSON the listener appended back out of the editor HTML.
 *
 * @return array<string, mixed>
 */
function emittedContext(string $html): array {
    expect($html)->toMatch('/data-craftai-element-context>/');
    preg_match('/data-craftai-element-context>(\{.*?\})<\/script>/s', $html, $m);
    // Json::htmlEncode escapes <>& as \uXXXX, which is still valid JSON,
    // so a plain decode round-trips it.
    return json_decode($m[1], true);
}

it('emits the draft identity (draftId + canonical) for a draft element', function () {
    $entry = Entry::factory()->section('posts')->title('Story')->create();
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    $event = new DefineElementEditorHtmlEvent([
        'element' => $draft,
        'html' => '<div class="fields">form</div>',
    ]);
    (new InjectElementContext())($event);

    // Original editor HTML is preserved; our tag is appended.
    expect($event->html)->toContain('<div class="fields">form</div>');

    $ctx = emittedContext($event->html);
    expect($ctx['draftId'])->toBe((int) $draft->draftId);
    expect($ctx['canonicalId'])->toBe((int) $entry->id);
    expect($ctx['elementId'])->toBe((int) $draft->id);
    expect($ctx['isDraft'])->toBeTrue();
    expect($ctx['isRevision'])->toBeFalse();
});

it('emits the canonical identity (null draftId) for a canonical element', function () {
    $entry = Entry::factory()->section('posts')->title('Story')->create();

    $event = new DefineElementEditorHtmlEvent([
        'element' => $entry,
        'html' => '<div>form</div>',
    ]);
    (new InjectElementContext())($event);

    $ctx = emittedContext($event->html);
    expect($ctx['draftId'])->toBeNull();
    expect($ctx['canonicalId'])->toBe((int) $entry->id);
    expect($ctx['elementId'])->toBe((int) $entry->id);
    expect($ctx['isDraft'])->toBeFalse();
});

it('emits valid, parseable JSON', function () {
    $entry = Entry::factory()->section('posts')->title('Story')->create();

    $event = new DefineElementEditorHtmlEvent([
        'element' => $entry,
        'html' => '',
    ]);
    (new InjectElementContext())($event);

    preg_match('/data-craftai-element-context>(.*?)<\/script>/s', $event->html, $m);
    expect($m[1])->not->toBe('');
    expect(json_decode($m[1], true))->toBeArray();
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
});
