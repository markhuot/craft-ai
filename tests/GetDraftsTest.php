<?php

use craft\elements\Entry;
use markhuot\craftai\tools\GetDrafts;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(UpsertDraft::class);
    $this->registry->register(GetDrafts::class);
});

function makeEntry(ToolRegistry $registry, string $title = 'Canonical'): array
{
    return decode($registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => $title,
    ]))['entry'];
}

it('returns an empty list when an entry has no drafts', function () {
    $entry = makeEntry($this->registry);

    $output = $this->registry->execute('get_drafts', ['entry' => $entry['id']]);

    expect($output->isError)->toBeFalse();
    expect(decode($output))->toBe([]);
});

it('lists drafts created for an entry', function () {
    $entry = makeEntry($this->registry);

    $this->registry->execute('upsert_draft', ['entry' => $entry['id'], 'title' => 'Draft One']);
    $this->registry->execute('upsert_draft', ['entry' => $entry['id'], 'title' => 'Draft Two']);

    $result = decode($this->registry->execute('get_drafts', ['entry' => $entry['id']]));

    expect($result)->toHaveCount(2);
    $titles = array_column($result, 'title');
    sort($titles);
    expect($titles)->toBe(['Draft One', 'Draft Two']);
});

it('requires an entry argument', function () {
    $result = $this->registry->execute('get_drafts', []);

    expect($result->isError)->toBeTrue();
});

it('errors on an unknown entry id', function () {
    $result = $this->registry->execute('get_drafts', ['entry' => 999999]);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});
