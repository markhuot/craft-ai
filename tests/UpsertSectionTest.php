<?php

use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertSection;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertSection::class);
});

it('updates an existing section without requiring entryTypes', function () {
    $section = Section::factory()->name('News')->handle('news')->create();

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'enableVersioning' => false,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $reloaded = Craft::$app->entries->getSectionById($section->id);
    expect($reloaded->enableVersioning)->toBeFalse();
    expect($reloaded->getEntryTypes())->not->toBeEmpty();
});

it('rejects an empty entryTypes list when explicitly passed', function () {
    $section = Section::factory()->name('News')->handle('news')->create();

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'entryTypes' => [],
    ]);

    expect($output->isError)->toBeTrue();
});

it('requires entryTypes when creating', function () {
    $output = $this->registry->execute('upsert_section', [
        'name' => 'Posts',
        'handle' => 'posts',
        'type' => 'channel',
    ]);

    expect($output->isError)->toBeTrue();
});
