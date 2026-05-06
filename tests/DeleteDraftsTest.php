<?php

use craft\elements\Entry;
use markhuot\craftai\tools\DeleteDrafts;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(UpsertDraft::class);
    $this->registry->register(DeleteDrafts::class);
});

it('deletes drafts by draftId', function () {
    $entry = json_decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Canonical',
    ])->text, true)['entry'];
    $draft = json_decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Draft',
    ])->text, true)['draft'];

    $output = $this->registry->execute('delete_drafts', ['ids' => [$draft['draftId']]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['results'][(string) $draft['draftId']]['deleted'])->toBeTrue();

    expect(Entry::find()->draftId($draft['draftId'])->status(null)->exists())->toBeFalse();
    expect(Entry::find()->id($entry['id'])->status(null)->exists())->toBeTrue();
});

it('errors on unknown draftId', function () {
    $output = $this->registry->execute('delete_drafts', ['ids' => [999999]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['results']['999999']['deleted'])->toBeFalse();
});
