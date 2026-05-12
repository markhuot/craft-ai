<?php

use Mcp\Schema\Tool as McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use markhuot\craftai\mcp\ServerFactory;
use markhuot\craftai\tools\DeleteFields;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Pulls the array of tools that ServerFactory queued on the SDK Builder. The
 * Builder hasn't constructed Mcp\Schema\Tool objects yet at this point —
 * we're inspecting the raw addTool() arguments to verify what ServerFactory
 * passed in.
 *
 * @return list<array<string, mixed>>
 */
function serverFactoryQueuedTools(ServerFactory $factory): array
{
    $build = new ReflectionMethod($factory, 'build');
    $registerTool = new ReflectionMethod($factory, 'registerTool');

    $builder = Server::builder();
    $registryProp = new ReflectionProperty($factory, 'registry');
    $registry = $registryProp->getValue($factory);

    foreach ($registry->descriptors(includeCpOnly: false, onlyAllowed: true) as $descriptor) {
        $registerTool->invoke($factory, $builder, $descriptor);
    }

    $toolsProp = new ReflectionProperty(Builder::class, 'tools');

    return $toolsProp->getValue($builder);
}

it('builds an mcp/sdk Server from the registered tools', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    $server = (new ServerFactory($registry))->build();

    expect($server)->toBeInstanceOf(Server::class);
});

it('serializes a param-less tool with properties as a JSON object, not an array', function () {
    // Regression: Claude Code's MCP client rejects tools/list responses where
    // any tool's inputSchema.properties serializes as `[]` instead of `{}`.
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    $tools = serverFactoryQueuedTools(new ServerFactory($registry));

    expect($tools)->toHaveCount(1);
    $json = json_encode($tools[0]['inputSchema']);
    expect($json)->toContain('"properties":{}')
        ->and($json)->not->toContain('"properties":[]');
});

it('plumbs tool annotations through to the SDK so destructiveHint reaches the wire', function () {
    // Regression: descriptor-level annotations (destructiveHint, idempotentHint)
    // were silently dropped because ServerFactory wasn't passing them to addTool.
    $registry = new ToolRegistry();
    $registry->register(DeleteFields::class);

    $tools = serverFactoryQueuedTools(new ServerFactory($registry));

    expect($tools)->toHaveCount(1);
    expect($tools[0]['annotations'])->toBeInstanceOf(ToolAnnotations::class);
    expect($tools[0]['annotations']->destructiveHint)->toBeTrue();
    expect($tools[0]['annotations']->idempotentHint)->toBeTrue();
});
