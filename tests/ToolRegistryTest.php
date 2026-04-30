<?php

use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\Tool;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;

/** Echo back the input for testing */
class ToolRegistryEchoFixture extends Tool
{
    public function __invoke(string $message, int $repeat = 1): string
    {
        return str_repeat($message, $repeat);
    }
}

/** Always throws to verify error handling */
class ToolRegistryThrowingFixture extends Tool
{
    public function __invoke(): string
    {
        throw new \RuntimeException('boom');
    }
}

it('registers a tool and lists its descriptor', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    $descriptors = $registry->descriptors();

    expect($descriptors)->toHaveCount(1);
    expect($descriptors[0]->name)->toBe('get_health');
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

it('catches exceptions thrown by tools and returns an error ToolOutput', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryThrowingFixture::class);

    $output = $registry->execute('tool_registry_throwing_fixture', []);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toBe('boom');
});
