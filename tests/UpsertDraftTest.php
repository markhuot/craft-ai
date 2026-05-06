<?php

use craft\elements\Entry;
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
    ]));
}

it('creates a draft of a canonical entry', function () {
    $entry = canonicalEntry($this->registry);

    $output = $this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'My Draft',
    ]);

    expect($output->isError)->toBeFalse();
    $draft = decode($output);
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
    $created = decode($output);

    $draft = Entry::find()->draftId($created['draftId'])->status(null)->one();
    expect($draft->draftName)->toBe('Editorial pass');
    expect($draft->draftNotes)->toBe('Tightened the intro');
});

it('updates an existing draft by draftId', function () {
    $entry = canonicalEntry($this->registry);
    $created = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Original Draft',
    ]));

    $output = $this->registry->execute('upsert_draft', [
        'draftId' => $created['draftId'], 'title' => 'Updated Draft',
    ]);

    expect($output->isError)->toBeFalse();
    expect(decode($output)['draftId'])->toBe($created['draftId']);

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
    $draft = decode($output);
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
    ]));

    $output = $this->registry->execute('upsert_draft', ['draftId' => $created['draftId']]);

    expect($output->isError)->toBeFalse();
});
