<?php

namespace markhuot\craftai\mcp;

use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\ToolSchema;

class McpServer
{
    public function __construct(
        private readonly ToolRegistry $registry,
    ) {}

    /**
     * @param resource $input
     * @param resource $output
     */
    public function run($input = STDIN, $output = STDOUT): void
    {
        while (($line = fgets($input)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            /** @var array{method?: string, id?: string|int|null, params?: array<string, mixed>} $request */
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $response = $this->handleRequest($request);

            if ($response !== null) {
                fwrite($output, json_encode($response, JSON_THROW_ON_ERROR) . "\n");
                fflush($output);
            }
        }
    }

    /**
     * @param array{method?: string, id?: string|int|null, params?: array<string, mixed>} $request
     * @return array<string, mixed>|null
     */
    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'initialized' => null,
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $request['params'] ?? []),
            default => self::errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function handleInitialize(string|int|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => new \stdClass(),
                ],
                'serverInfo' => [
                    'name' => 'craft-ai',
                    'version' => '1.0.0',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(string|int|null $id): array
    {
        $tools = array_map(
            static fn (ToolSchema $schema): array => $schema->toMcpTool(),
            $this->registry->schemas(),
        );

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => $tools,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(string|int|null $id, array $params): array
    {
        /** @var string $name */
        $name = $params['name'] ?? '';
        /** @var array<string, mixed> $arguments */
        $arguments = $params['arguments'] ?? [];

        $output = $this->registry->execute($name, $arguments);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $output->text,
                    ],
                ],
                'isError' => $output->isError,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorResponse(string|int|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
