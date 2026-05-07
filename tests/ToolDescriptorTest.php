<?php

use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\Tool;
use markhuot\craftai\tools\ToolDescriptor;
use markhuot\craftai\tools\ToolKind;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;

/** A tool with parameters for testing */
class ToolDescriptorTestFixture extends Tool
{
    public function __invoke(
        string $name,
        #[Description('The age in years')]
        int $age,
        ?bool $verbose = null,
    ): string {
        return "{$name}/{$age}/" . ($verbose ? 'v' : 'q');
    }
}

#[ToolAttribute(name: 'custom_handle', description: 'Custom override description')]
class ToolDescriptorAttributeFixture extends Tool
{
    public function __invoke(): string
    {
        return 'ok';
    }
}

it('reflects the tool kind onto the descriptor', function () {
    expect((new ToolDescriptor(GetHealth::class))->kind)->toBe(ToolKind::Read);
    expect((new ToolDescriptor(UpsertDraft::class))->kind)->toBe(ToolKind::DraftWrite);
    // UpsertEntry doesn't override KIND, so it inherits the safe default.
    expect((new ToolDescriptor(UpsertEntry::class))->kind)->toBe(ToolKind::LiveWrite);
});

it('derives the tool name from the class name in snake_case', function () {
    $descriptor = new ToolDescriptor(GetHealth::class);

    expect($descriptor->name)->toBe('get_health');
});

it('extracts the description from the class docblock', function () {
    $descriptor = new ToolDescriptor(GetHealth::class);

    expect($descriptor->description)->toContain('Check the health');
});

it('honors the #[Tool] attribute for name and description overrides', function () {
    $descriptor = new ToolDescriptor(ToolDescriptorAttributeFixture::class);

    expect($descriptor->name)->toBe('custom_handle');
    expect($descriptor->description)->toBe('Custom override description');
});

it('builds an empty input schema for tools with no parameters', function () {
    $descriptor = new ToolDescriptor(GetHealth::class);

    expect($descriptor->inputSchema)->toBe([
        'type' => 'object',
        'properties' => [],
        'required' => [],
    ]);
});

it('reflects parameters into JSON Schema with correct types and required list', function () {
    $descriptor = new ToolDescriptor(ToolDescriptorTestFixture::class);

    expect($descriptor->inputSchema['properties'])->toMatchArray([
        'name' => ['type' => 'string'],
        'age' => ['type' => 'integer', 'description' => 'The age in years'],
    ]);
    expect($descriptor->inputSchema['properties']['verbose'])->toMatchArray(['type' => 'boolean']);
    expect($descriptor->inputSchema['required'])->toBe(['name', 'age']);
});

it('includes default values in the JSON schema for parameters with non-null defaults', function () {
    $fixture = new class extends Tool {
        public function __invoke(
            bool $hasTitleField = true,
            int $count = 5,
            ?string $optional = null,
        ): string {
            return 'ok';
        }
    };

    $descriptor = new ToolDescriptor($fixture::class);

    expect($descriptor->inputSchema['properties']['hasTitleField'])->toMatchArray([
        'type' => 'boolean',
        'default' => true,
    ]);
    expect($descriptor->inputSchema['properties']['count'])->toMatchArray([
        'type' => 'integer',
        'default' => 5,
    ]);
    expect($descriptor->inputSchema['properties']['optional'])->not->toHaveKey('default');
});

it('emits the Anthropic-shaped tool definition with input_schema', function () {
    $tool = (new ToolDescriptor(GetHealth::class))->toAnthropicTool();

    expect($tool)->toHaveKeys(['name', 'description', 'input_schema']);
    expect($tool['name'])->toBe('get_health');
});

it('emits the OpenAI-shaped tool definition with type=function and parameters', function () {
    $tool = (new ToolDescriptor(GetHealth::class))->toOpenAiTool();

    expect($tool['type'])->toBe('function');
    expect($tool['function']['name'])->toBe('get_health');
    expect($tool['function'])->toHaveKey('parameters');
});

it('serializes empty OpenAI tool parameters as a JSON object, not an array', function () {
    // Strict OpenAI-compatible providers (DeepSeek via opencode.ai) reject
    // `properties: []` and require `properties: {}`.
    $tool = (new ToolDescriptor(GetHealth::class))->toOpenAiTool();
    $json = json_encode($tool);

    expect($json)->toContain('"properties":{}');
    expect($json)->not->toContain('"properties":[]');
});

it('emits the MCP-shaped tool definition with inputSchema (camelCase)', function () {
    $tool = (new ToolDescriptor(GetHealth::class))->toMcpTool();

    expect($tool)->toHaveKeys(['name', 'description', 'inputSchema']);
    expect($tool['name'])->toBe('get_health');
});
