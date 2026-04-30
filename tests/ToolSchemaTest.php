<?php

use markhuot\craftai\attributes\Description;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolSchema;

#[Description('A tool with parameters for testing')]
class ToolSchemaTestFixture
{
    public function __invoke(
        string $name,
        #[Description('The age in years')]
        int $age,
        ?bool $verbose = null,
    ): ToolOutput {
        return new ToolOutput("{$name}/{$age}/" . ($verbose ? 'v' : 'q'));
    }
}

it('derives the tool name from the class name in snake_case', function () {
    $schema = new ToolSchema(GetHealth::class);

    expect($schema->name)->toBe('get_health');
});

it('extracts the description from the class attribute', function () {
    $schema = new ToolSchema(GetHealth::class);

    expect($schema->description)->toContain('Check the health');
});

it('builds an empty input schema for tools with no parameters', function () {
    $schema = new ToolSchema(GetHealth::class);

    expect($schema->inputSchema)->toBe([
        'type' => 'object',
        'properties' => [],
        'required' => [],
    ]);
});

it('reflects parameters into JSON Schema with correct types and required list', function () {
    $schema = new ToolSchema(ToolSchemaTestFixture::class);

    expect($schema->inputSchema['properties'])->toMatchArray([
        'name' => ['type' => 'string'],
        'age' => ['type' => 'integer', 'description' => 'The age in years'],
    ]);
    expect($schema->inputSchema['properties']['verbose'])->toMatchArray(['type' => 'boolean']);
    expect($schema->inputSchema['required'])->toBe(['name', 'age']);
});

it('emits the Anthropic-shaped tool definition with input_schema', function () {
    $schema = new ToolSchema(GetHealth::class);

    $tool = $schema->toAnthropicTool();

    expect($tool)->toHaveKeys(['name', 'description', 'input_schema']);
    expect($tool['name'])->toBe('get_health');
});

it('emits the MCP-shaped tool definition with inputSchema (camelCase)', function () {
    $schema = new ToolSchema(GetHealth::class);

    $tool = $schema->toMcpTool();

    expect($tool)->toHaveKeys(['name', 'description', 'inputSchema']);
    expect($tool['name'])->toBe('get_health');
});
