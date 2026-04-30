<?php

use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();
});

it('returns full entry details by ID', function () {
    $entry = Entry::factory()->section('posts')->title('Hello World')->create();

    $tool = new GetEntry();
    $result = $tool(id: $entry->id);

    expect($result)->toBeArray();
    expect($result['id'])->toBe($entry->id);
    expect($result['title'])->toBe('Hello World');
});

it('returns an error when the entry does not exist', function () {
    $tool = new GetEntry();
    $result = $tool(id: 999999);

    expect($result)->toBeInstanceOf(ToolOutput::class);
    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('finds disabled entries by ID', function () {
    $entry = Entry::factory()->section('posts')->title('Hidden')->enabled(false)->create();

    $tool = new GetEntry();
    $result = $tool(id: $entry->id);

    expect($result)->toBeArray();
    expect($result['title'])->toBe('Hidden');
});
