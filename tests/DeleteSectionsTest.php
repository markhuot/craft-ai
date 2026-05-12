<?php

use markhuot\craftai\tools\DeleteSections;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(DeleteSections::class);
});

it('deletes sections by ID', function () {
    $a = Section::factory()->name('A')->handle('a')->create();
    $b = Section::factory()->name('B')->handle('b')->create();

    $output = $this->registry->execute('delete_sections', ['ids' => [$a->id, $b->id]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results'][(string) $a->id]['deleted'])->toBeTrue();
    expect($payload['data']['results'][(string) $b->id]['deleted'])->toBeTrue();

    expect(Craft::$app->entries->getSectionById($a->id))->toBeNull();
    expect(Craft::$app->entries->getSectionById($b->id))->toBeNull();
});

it('reports unknown section IDs', function () {
    $output = $this->registry->execute('delete_sections', ['ids' => [999999]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results']['999999']['deleted'])->toBeFalse();
    expect($payload['data']['results']['999999']['error'])->toContain('999999');
});

it('exposes a destructive annotation on the MCP descriptor', function () {
    $descriptor = $this->registry->describe('delete_sections');
    $mcp = $descriptor->toMcpTool();

    expect($mcp['annotations']['destructiveHint'])->toBeTrue();
    expect($mcp['annotations']['idempotentHint'])->toBeTrue();
});
