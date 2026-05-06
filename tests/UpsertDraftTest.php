<?php

use craft\elements\Entry;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(UpsertDraft::class);
});

function canonicalEntry(ToolRegistry $registry): array
{
    return decode($registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Canonical',
    ]))['entry'];
}

it('creates a draft of a canonical entry', function () {
    $entry = canonicalEntry($this->registry);

    $output = $this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'My Draft',
    ]);

    expect($output->isError)->toBeFalse();
    $draft = decode($output)['draft'];
    expect($draft['title'])->toBe('My Draft');
    expect($draft['draftId'])->not->toBeNull();
    expect($draft['canonicalId'])->toBe($entry['id']);
});

it('sets the draft name and notes on creation', function () {
    $entry = canonicalEntry($this->registry);

    $output = $this->registry->execute('upsert_draft', [
        'entry' => $entry['id'],
        'name' => 'Editorial pass',
        'notes' => 'Tightened the intro',
    ]);

    expect($output->isError)->toBeFalse();
    $created = decode($output)['draft'];

    $draft = Entry::find()->draftId($created['draftId'])->status(null)->one();
    expect($draft->draftName)->toBe('Editorial pass');
    expect($draft->draftNotes)->toBe('Tightened the intro');
});

it('updates an existing draft by draftId', function () {
    $entry = canonicalEntry($this->registry);
    $created = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Original Draft',
    ]))['draft'];

    $output = $this->registry->execute('upsert_draft', [
        'draftId' => $created['draftId'], 'title' => 'Updated Draft',
    ]);

    expect($output->isError)->toBeFalse();
    expect(decode($output)['draft']['draftId'])->toBe($created['draftId']);

    $draft = Entry::find()->draftId($created['draftId'])->status(null)->one();
    expect($draft->title)->toBe('Updated Draft');
});

it('requires entry or section when no draftId is given', function () {
    $result = $this->registry->execute('upsert_draft', ['title' => 'Orphan']);

    expect($result->isError)->toBeTrue();
});

it('creates a fresh draft with no canonical entry from a section', function () {
    $output = $this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
        'name' => 'Initial pass',
    ]);

    expect($output->isError)->toBeFalse();
    $draft = decode($output)['draft'];
    expect($draft['title'])->toBe('Fresh Draft');
    expect($draft['draftId'])->not->toBeNull();

    $reloaded = Entry::find()->draftId($draft['draftId'])->status(null)->one();
    expect($reloaded)->not->toBeNull();
    expect($reloaded->draftName)->toBe('Initial pass');
});

it('errors when section is unknown', function () {
    $result = $this->registry->execute('upsert_draft', [
        'section' => 'does-not-exist',
        'title' => 'Nope',
    ]);

    expect($result->isError)->toBeTrue();
});

it('errors on an unknown draftId', function () {
    $result = $this->registry->execute('upsert_draft', ['draftId' => 999999, 'title' => 'Nope']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('errors on an unknown canonical entry id', function () {
    $result = $this->registry->execute('upsert_draft', ['entry' => 999999, 'title' => 'Nope']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('skips create-only required rules when updating an existing draft', function () {
    $entry = canonicalEntry($this->registry);
    $created = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'],
    ]))['draft'];

    $output = $this->registry->execute('upsert_draft', ['draftId' => $created['draftId']]);

    expect($output->isError)->toBeFalse();
});

it('returns a tokenized preview URL for a draft of a canonical entry', function () {
    $entry = canonicalEntry($this->registry);

    $draft = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'My Draft',
    ]))['draft'];

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($draft['url'])->toContain("$tokenParam=");

    parse_str(parse_url($draft['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route)->not->toBeFalse();
    expect($route[0])->toBe('preview/preview');
    expect($route[1]['draftId'])->toBe($draft['draftId']);
    expect($route[1]['canonicalId'])->toBe($entry['id']);
    expect($route[1]['elementType'])->toBe(Entry::class);
});

it('returns a tokenized preview URL for a fresh draft', function () {
    $draft = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]))['draft'];

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($draft['url'])->toContain("$tokenParam=");

    parse_str(parse_url($draft['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route)->not->toBeFalse();
    expect($route[0])->toBe('preview/preview');
    expect($route[1]['draftId'])->toBe($draft['draftId']);
});

it('returns a tokenized preview URL when updating a draft', function () {
    $entry = canonicalEntry($this->registry);
    $created = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Original',
    ]))['draft'];

    $updated = decode($this->registry->execute('upsert_draft', [
        'draftId' => $created['draftId'], 'title' => 'Updated',
    ]))['draft'];

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($updated['url'])->toContain("$tokenParam=");

    parse_str(parse_url($updated['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route[1]['draftId'])->toBe($updated['draftId']);
});

it('returns a null url for a draft in a section without front-end URLs', function () {
    Section::factory()->name('Hidden')->handle('hidden')->hasUrls(false)->create();

    $draft = decode($this->registry->execute('upsert_draft', [
        'section' => 'hidden',
        'title' => 'Invisible',
    ]));

    expect($draft['url'])->toBeNull();
    expect($draft)->not->toHaveKey('notes');
    expect($draft)->not->toHaveKey('draft');
});

it('wraps the response with a notes prompt to call open_preview when the draft has a URL', function () {
    $entry = canonicalEntry($this->registry);

    $output = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Previewable',
    ]));

    expect($output)->toHaveKeys(['notes', 'draft']);
    expect($output['notes'])->toContain('open_preview');
    expect($output['notes'])->toContain($output['draft']['url']);
});

it('emits a generic Draft saved. note for MCP clients without referencing open_preview', function () {
    $entry = canonicalEntry($this->registry);

    /** @var ToolContext $context */
    $context = Craft::$container->get(ToolContext::class);
    $context->begin(null, null, ClientType::MCP);

    $output = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'MCP Draft',
    ]));

    expect($output)->toHaveKeys(['notes', 'draft']);
    expect($output['notes'])->toBe('Draft saved.');
    expect($output['notes'])->not->toContain('open_preview');
    expect($output['draft']['title'])->toBe('MCP Draft');
});
