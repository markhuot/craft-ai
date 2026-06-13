<?php

use craft\elements\Entry;
use markhuot\craftai\tools\ApplyDraft;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(UpsertDraft::class);
    $this->registry->register(ApplyDraft::class);
});

it('applies a draft to its canonical entry and deletes the draft', function () {
    $entry = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Canonical',
    ]))['data']['entry'];

    $draft = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Edited',
    ]))['data']['draft'];

    $output = $this->registry->execute('apply_draft', ['draftId' => $draft['draftId']]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['_notes'])->toContain('Applied draft');

    expect(Entry::find()->draftId($draft['draftId'])->status(null)->exists())->toBeFalse();

    $canonical = Entry::find()->id($entry['id'])->status(null)->one();
    expect($canonical)->not->toBeNull();
    expect($canonical->title)->toBe('Edited');
});

it('publishes a fresh draft (no canonical) as a new canonical entry', function () {
    $draft = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]))['data']['draft'];

    expect($draft['canonicalId'])->toBe($draft['id']);

    $output = $this->registry->execute('apply_draft', ['draftId' => $draft['draftId']]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['_notes'])->toContain('Published fresh draft');

    $canonical = Entry::find()->id($draft['id'])->status(null)->one();
    expect($canonical)->not->toBeNull();
    expect($canonical->getIsDraft())->toBeFalse();
    expect($canonical->title)->toBe('Fresh Draft');
});

it('errors on an unknown draftId', function () {
    $result = $this->registry->execute('apply_draft', ['draftId' => 999999]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('requires a draftId', function () {
    $result = $this->registry->execute('apply_draft', []);

    expect($result->isError)->toBeTrue();
});
