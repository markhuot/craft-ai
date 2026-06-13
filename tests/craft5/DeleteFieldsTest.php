<?php

use craft\fields\PlainText;
use markhuot\craftai\tools\DeleteFields;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(DeleteFields::class);
});

function makeField(string $name, string $handle): \craft\base\FieldInterface
{
    $field = Craft::$app->fields->createField([
        'type' => PlainText::class,
        'name' => $name,
        'handle' => $handle,
    ]);
    Craft::$app->fields->saveField($field);

    return $field;
}

it('deletes fields by ID', function () {
    $a = makeField('A', 'a');
    $b = makeField('B', 'b');

    $output = $this->registry->execute('delete_fields', ['ids' => [$a->id, $b->id]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results'][(string) $a->id]['deleted'])->toBeTrue();
    expect($payload['data']['results'][(string) $b->id]['deleted'])->toBeTrue();

    expect(Craft::$app->fields->getFieldById($a->id))->toBeNull();
    expect(Craft::$app->fields->getFieldById($b->id))->toBeNull();
});

it('reports unknown field IDs', function () {
    $output = $this->registry->execute('delete_fields', ['ids' => [999999]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results']['999999']['deleted'])->toBeFalse();
    expect($payload['data']['results']['999999']['error'])->toContain('999999');
});

it('exposes a destructive annotation on the MCP descriptor', function () {
    $descriptor = $this->registry->describe('delete_fields');
    $mcp = $descriptor->toMcpTool();

    expect($mcp['annotations']['destructiveHint'])->toBeTrue();
    expect($mcp['annotations']['idempotentHint'])->toBeTrue();
});
