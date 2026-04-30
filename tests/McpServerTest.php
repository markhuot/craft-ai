<?php

use markhuot\craftai\mcp\McpServer;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolRegistry;

/**
 * @param list<array<string, mixed>> $requests
 * @return list<array<string, mixed>>
 */
function mcpRoundTrip(McpServer $server, array $requests): array
{
    $input = fopen('php://memory', 'r+');
    $output = fopen('php://memory', 'r+');

    foreach ($requests as $req) {
        fwrite($input, json_encode($req, JSON_THROW_ON_ERROR) . "\n");
    }
    rewind($input);

    $server->run($input, $output);

    rewind($output);
    $raw = stream_get_contents($output);
    fclose($input);
    fclose($output);

    $responses = [];
    foreach (explode("\n", trim($raw)) as $line) {
        if ($line !== '') {
            $responses[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    return $responses;
}

it('responds to initialize with protocol version and tools capability', function () {
    $registry = new ToolRegistry();
    $server = new McpServer($registry);

    [$response] = mcpRoundTrip($server, [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
    ]);

    expect($response['id'])->toBe(1);
    expect($response['result']['protocolVersion'])->toBe('2024-11-05');
    expect($response['result']['capabilities'])->toHaveKey('tools');
    expect($response['result']['serverInfo']['name'])->toBe('craft-ai');
});

it('lists tools with the MCP-shaped schema', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);
    $server = new McpServer($registry);

    [$response] = mcpRoundTrip($server, [
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
    ]);

    expect($response['result']['tools'])->toHaveCount(1);
    $tool = $response['result']['tools'][0];
    expect($tool['name'])->toBe('get_health');
    expect($tool)->toHaveKey('inputSchema');
});

it('calls a tool and returns its content blocks', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);
    $server = new McpServer($registry);

    [$response] = mcpRoundTrip($server, [
        [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'get_health', 'arguments' => []],
        ],
    ]);

    expect($response['result']['content'][0]['type'])->toBe('text');
    expect($response['result']['content'][0]['text'])->toContain('operational');
    expect($response['result']['isError'])->toBeFalse();
});

it('returns a JSON-RPC error for unknown methods', function () {
    $registry = new ToolRegistry();
    $server = new McpServer($registry);

    [$response] = mcpRoundTrip($server, [
        ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'totally/made-up'],
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32601);
});

it('does not respond to the initialized notification', function () {
    $registry = new ToolRegistry();
    $server = new McpServer($registry);

    $responses = mcpRoundTrip($server, [
        ['jsonrpc' => '2.0', 'method' => 'initialized'],
    ]);

    expect($responses)->toBeEmpty();
});
