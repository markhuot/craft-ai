<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\permissions\ToolPermissionDeniedException;
use markhuot\craftai\permissions\ToolPermissions;
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

it('omits cp-only tools when descriptors are requested without them', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);
    $registry->register(ToolRegistryEchoFixture::class, cpOnly: true);

    $all = $registry->descriptors();
    $public = $registry->descriptors(includeCpOnly: false);

    expect($all)->toHaveCount(2);
    expect($public)->toHaveCount(1);
    expect($public[0]->name)->toBe('get_health');
});

it('returns an error ToolOutput when no user is logged in', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    Craft::$app->getUser()->setIdentity(null);

    $output = $registry->execute('get_health', []);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('do not have permission');
    expect($output->text)->toContain(ToolPermissions::name('get_health'));
});

it('throws a permission exception from assertPermission for a guest', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    Craft::$app->getUser()->setIdentity(null);

    expect(fn () => $registry->assertPermission('get_health'))
        ->toThrow(ToolPermissionDeniedException::class);
});

it('catches exceptions thrown by tools and returns an error ToolOutput', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryThrowingFixture::class);

    $output = $registry->execute('tool_registry_throwing_fixture', []);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toBe('boom');
});
