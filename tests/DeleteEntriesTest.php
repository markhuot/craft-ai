<?php

use craft\elements\Entry;
use markhuot\craftai\tools\DeleteEntries;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(DeleteEntries::class);
});

function createEntry(ToolRegistry $registry, string $title): array
{
    return json_decode($registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => $title,
    ])->text, true);
}

it('deletes a list of entries', function () {
    $a = createEntry($this->registry, 'A');
    $b = createEntry($this->registry, 'B');

    $output = $this->registry->execute('delete_entries', ['ids' => [$a['id'], $b['id']]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['results'][(string) $a['id']]['deleted'])->toBeTrue();
    expect($payload['results'][(string) $b['id']]['deleted'])->toBeTrue();

    expect(Entry::find()->id($a['id'])->status(null)->exists())->toBeFalse();
    expect(Entry::find()->id($b['id'])->status(null)->exists())->toBeFalse();
});

it('reports an error for unknown ids without aborting the batch', function () {
    $a = createEntry($this->registry, 'A');

    $output = $this->registry->execute('delete_entries', ['ids' => [$a['id'], 999999]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['results'][(string) $a['id']]['deleted'])->toBeTrue();
    expect($payload['results']['999999']['deleted'])->toBeFalse();
    expect($payload['results']['999999']['error'])->toContain('999999');
});

it('hard-deletes when hardDelete is true', function () {
    $a = createEntry($this->registry, 'A');

    $output = $this->registry->execute('delete_entries', [
        'ids' => [$a['id']],
        'hardDelete' => true,
    ]);

    expect($output->isError)->toBeFalse($output->text);
    expect(Entry::find()->id($a['id'])->status(null)->trashed(null)->exists())->toBeFalse();
});
