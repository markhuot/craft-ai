<?php

use craft\fields\PlainText;
use markhuot\craftai\tools\DiffRevisions;
use markhuot\craftai\tools\GetRevision;
use markhuot\craftai\tools\GetRevisions;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $body = Field::factory()->name('Body')->handle('body')->type(PlainText::class);
    Section::factory()->name('Posts')->handle('posts')->fields($body)->create();

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
    $entry = Entry::factory()->section('posts')->title('V1')->create();
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
    expect($data[0]['ref'])->toBe('rev:'.$r2);
    expect($data[0]['revisionId'])->toBe($r2);
    expect($data[1]['ref'])->toBe('rev:'.$r1);
    expect($data[0]['revisionNum'])->toBeGreaterThan($data[1]['revisionNum']);
});

it('errors when get_revisions is given an unknown entry', function () {
    $out = $this->registry->execute('get_revisions', ['entry' => 999999]);
    expect($out->isError)->toBeTrue();
});

it('reads a single revision via get_revision', function () {
    $entry = Entry::factory()->section('posts')->title('Readable')->create();
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
    $entry = Entry::factory()->section('posts')->title('Title A')->create();
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
    $entry = Entry::factory()->section('posts')->title('Now A')->create();
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
    $entry = Entry::factory()->section('posts')->title('X')->create();
    $out = $this->registry->execute('diff_revisions', ['entryId' => $entry->id, 'a' => 'rev:999999', 'b' => 'current']);
    expect($out->isError)->toBeTrue();
    expect($out->text)->toContain('999999');
});
