<?php

use markhuot\craftai\tools\DeleteEntryTypes;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntryType;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntryType::class);
    $this->registry->register(DeleteEntryTypes::class);
});

it('deletes entry types by ID', function () {
    $created = json_decode($this->registry->execute('upsert_entry_type', [
        'name' => 'Article', 'handle' => 'article',
    ])->text, true);

    $output = $this->registry->execute('delete_entry_types', ['ids' => [$created['id']]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['results'][(string) $created['id']]['deleted'])->toBeTrue();

    expect(Craft::$app->entries->getEntryTypeByHandle('article'))->toBeNull();
});

it('reports unknown entry type IDs', function () {
    $output = $this->registry->execute('delete_entry_types', ['ids' => [999999]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['results']['999999']['deleted'])->toBeFalse();
});
