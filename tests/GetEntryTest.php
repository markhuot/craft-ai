<?php

use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(GetEntry::class);
});

it('returns full entry details by ID', function () {
    $entry = Entry::factory()->section('posts')->title('Hello World')->create();

    $output = $this->registry->execute('get_entry', ['id' => $entry->id]);

    expect($output->isError)->toBeFalse();
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
    expect($payload['data']['id'])->toBe($entry->id);
    expect($payload['data']['title'])->toBe('Hello World');
});

it('returns an error when the entry does not exist', function () {
    $output = $this->registry->execute('get_entry', ['id' => 999999]);

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});

it('finds disabled entries by ID', function () {
    $entry = Entry::factory()->section('posts')->title('Hidden')->enabled(false)->create();

    $output = $this->registry->execute('get_entry', ['id' => $entry->id]);

    expect($output->isError)->toBeFalse();
    $payload = json_decode($output->text, true);
    expect($payload['data']['title'])->toBe('Hidden');
});
