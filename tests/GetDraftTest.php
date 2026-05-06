<?php

use markhuot\craftai\tools\GetDraft;
use markhuot\craftai\tools\GetDrafts;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(UpsertDraft::class);
    $this->registry->register(GetEntry::class);
    $this->registry->register(GetDraft::class);
    $this->registry->register(GetDrafts::class);
});

it('round-trips a fresh draft via get_draft', function () {
    $created = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]));

    expect($created['draftId'])->not->toBeNull();

    $output = $this->registry->execute('get_draft', ['draftId' => $created['draftId']]);

    expect($output->isError)->toBeFalse();
    $fetched = decode($output);
    expect($fetched['draftId'])->toBe($created['draftId']);
    expect($fetched['title'])->toBe('Fresh Draft');
});

it('round-trips a draft of a canonical entry via get_draft', function () {
    $entry = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Canonical',
    ]));
    $draft = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Editorial',
    ]));

    $fetched = decode($this->registry->execute('get_draft', ['draftId' => $draft['draftId']]));

    expect($fetched['draftId'])->toBe($draft['draftId']);
    expect($fetched['canonicalId'])->toBe($entry['id']);
    expect($fetched['title'])->toBe('Editorial');
});

it('errors when get_draft is given an unknown draftId', function () {
    $output = $this->registry->execute('get_draft', ['draftId' => 999999]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});

it('does not return a draft from get_entry', function () {
    $created = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]));

    $output = $this->registry->execute('get_entry', ['id' => $created['id']]);

    expect($output->isError)->toBeTrue();
});

it('returns a tokenized preview URL routed to the draft', function () {
    $created = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Drafted',
    ]));

    $fetched = decode($this->registry->execute('get_draft', ['draftId' => $created['draftId']]));

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($fetched['url'])->toContain("$tokenParam=");

    parse_str(parse_url($fetched['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route)->not->toBeFalse();
    expect($route[0])->toBe('preview/preview');
    expect($route[1]['draftId'])->toBe($created['draftId']);
});
