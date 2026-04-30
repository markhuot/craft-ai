<?php

use markhuot\craftai\attributes\Description;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;

#[Description('Echo back the input for testing')]
class ToolRegistryEchoFixture
{
    public function __invoke(string $message, int $repeat = 1): ToolOutput
    {
        return new ToolOutput(str_repeat($message, $repeat));
    }
}

it('registers a tool and lists its schema', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    $schemas = $registry->schemas();

    expect($schemas)->toHaveCount(1);
    expect($schemas[0]->name)->toBe('get_health');
});

it('executes a tool by name with named arguments', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryEchoFixture::class);

    $output = $registry->execute('tool_registry_echo_fixture', [
        'message' => 'hi',
        'repeat' => 3,
    ]);

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->text)->toBe('hihihi');
    expect($output->isError)->toBeFalse();
});

it('falls back to default values for omitted optional parameters', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryEchoFixture::class);

    $output = $registry->execute('tool_registry_echo_fixture', [
        'message' => 'once',
    ]);

    expect($output->text)->toBe('once');
});

it('returns an error ToolOutput when the tool is unknown', function () {
    $registry = new ToolRegistry();

    $output = $registry->execute('does_not_exist', []);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Unknown tool');
});
