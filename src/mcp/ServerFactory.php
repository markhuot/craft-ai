<?php

namespace markhuot\craftai\mcp;

use Mcp\Server;
use markhuot\craftai\tools\ToolDescriptor;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Builds an mcp/sdk Server with our manually-registered tools. Shared between
 * the HTTP transport (controllers/McpController) and any stdio entrypoint.
 */
class ServerFactory
{
    public function __construct(
        private readonly ToolRegistry $registry,
    ) {}

    public function build(): Server
    {
        $builder = Server::builder()
            ->setServerInfo('craft-ai', '1.0.0', 'Craft CMS AI plugin');

        foreach ($this->registry->descriptors() as $descriptor) {
            $this->registerTool($builder, $descriptor);
        }

        return $builder->build();
    }

    private function registerTool(\Mcp\Server\Builder $builder, ToolDescriptor $descriptor): void
    {
        // mcp/sdk reflects __invoke on the class string and instantiates via
        // its container. Our tools extend the same Tool base class the agent
        // loop uses, so the same class powers both surfaces.
        $builder->addTool(
            handler: $descriptor->toolClass,
            name: $descriptor->name,
            description: $descriptor->description,
            inputSchema: $descriptor->inputSchema,
        );
    }
}
