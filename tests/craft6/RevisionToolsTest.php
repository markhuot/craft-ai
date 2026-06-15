<?php

use CraftCms\Cms\Section\Enums\SectionType;

use CraftCms\Cms\Field\PlainText;
use markhuot\craftai\tools\DiffRevisions;
use markhuot\craftai\tools\GetRevision;
use markhuot\craftai\tools\GetRevisions;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $body = seedField('body', 'Body', PlainText::class);
    seedSection('posts', 'Posts', SectionType::Channel, [$body]);

    $this->registry = new ToolRegistry();
    $this->registry->register(GetRevisions::class);
    $this->registry->register(GetRevision::class);
    $this->registry->register(DiffRevisions::class);
});

function revision(\craft\elements\Entry $entry): int
{
    return Craft::$app->getRevisions()->createRevision($entry, null, null, [], force: true);
}

it('lists revisions newest-first via get_revisions', function () {
    $entry = seedEntry('posts', ['title' => 'V1']);
    $entry->setFieldValue('body', 'one');
    Craft::$app->elements->saveElement($entry);
    $r1 = revision($entry);

    $entry->title = 'V2';
    $entry->setFieldValue('body', 'two');
    Craft::$app->elements->saveElement($entry);
    $r2 = revision($entry);

    $out = $this->registry->execute('get_revisions', ['entry' => $entry->id]);
    expect($out->isError)->toBeFalse($out->text);

    $data = decode($out)['data'];
    expect(count($data))->toBeGreaterThanOrEqual(2);

    // Saving an entry can itself mint a revision, so $r1/$r2 aren't guaranteed
    // to be adjacent at indices 0/1 — assert their relative order instead (the
    // newer $r2 must list before the older $r1), which is what "newest-first"
    // actually means.
    $refs = array_column($data, 'ref');
    $posR2 = array_search('rev:'.$r2, $refs, true);
    $posR1 = array_search('rev:'.$r1, $refs, true);
    expect($posR2)->not->toBeFalse();
    expect($posR1)->not->toBeFalse();
    expect($posR2)->toBeLessThan($posR1);
    expect($data[$posR2]['revisionId'])->toBe($r2);
    expect($data[$posR2]['revisionNum'])->toBeGreaterThan($data[$posR1]['revisionNum']);
});

it('errors when get_revisions is given an unknown entry', function () {
    $out = $this->registry->execute('get_revisions', ['entry' => 999999]);
    expect($out->isError)->toBeTrue();
});

it('reads a single revision via get_revision', function () {
    $entry = seedEntry('posts', ['title' => 'Readable']);
    $entry->setFieldValue('body', 'content');
    Craft::$app->elements->saveElement($entry);
    $r1 = revision($entry);

    $out = $this->registry->execute('get_revision', ['revisionId' => $r1]);
    expect($out->isError)->toBeFalse($out->text);

    $payload = decode($out);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['data']['title'])->toBe('Readable');
    expect($payload['_notes'])->toContain('Revision');
});

it('errors when get_revision is given an unknown revision id', function () {
    $out = $this->registry->execute('get_revision', ['revisionId' => 999999]);
    expect($out->isError)->toBeTrue();
    expect($out->text)->toContain('999999');
});

it('diffs two revisions via diff_revisions', function () {
    $entry = seedEntry('posts', ['title' => 'Title A']);
    $entry->setFieldValue('body', 'alpha');
    Craft::$app->elements->saveElement($entry);
    $r1 = revision($entry);

    $entry->title = 'Title B';
    $entry->setFieldValue('body', 'beta');
    Craft::$app->elements->saveElement($entry);
    $r2 = revision($entry);

    $out = $this->registry->execute('diff_revisions', ['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'rev:'.$r2]);
    expect($out->isError)->toBeFalse($out->text);

    $data = decode($out)['data'];
    expect($data['summary']['changed'])->toBeGreaterThanOrEqual(2);

    $byHandle = collect($data['fields'])->keyBy('handle');
    expect($byHandle->get('title')['status'])->toBe('changed');
    expect($byHandle->get('body')['status'])->toBe('changed');
});

it('diffs a revision against current via diff_revisions', function () {
    $entry = seedEntry('posts', ['title' => 'Now A']);
    $entry->setFieldValue('body', 'first');
    Craft::$app->elements->saveElement($entry);
    $r1 = revision($entry);

    $entry->setFieldValue('body', 'second');
    Craft::$app->elements->saveElement($entry);

    $out = $this->registry->execute('diff_revisions', ['entryId' => $entry->id, 'a' => 'rev:'.$r1, 'b' => 'current']);
    expect($out->isError)->toBeFalse($out->text);

    $data = decode($out)['data'];
    expect($data['b']['ref'])->toBe('current');
    expect(collect($data['fields'])->firstWhere('handle', 'body')['status'])->toBe('changed');
});

it('errors when a version ref cannot be resolved', function () {
    $entry = seedEntry('posts', ['title' => 'X']);
    $out = $this->registry->execute('diff_revisions', ['entryId' => $entry->id, 'a' => 'rev:999999', 'b' => 'current']);
    expect($out->isError)->toBeTrue();
    expect($out->text)->toContain('999999');
});
