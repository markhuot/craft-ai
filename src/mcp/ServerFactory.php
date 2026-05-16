<?php

namespace markhuot\craftai\mcp;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\Plugin;
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
        private readonly ToolContext $toolContext = new ToolContext(),
    ) {}

    public function build(): Server
    {
        $builder = Server::builder()
            ->setServerInfo('craft-ai', '1.0.0', 'Craft CMS AI plugin')
            ->setSession(new Psr16SessionStore(
                cache: new Psr16YiiCache(Plugin::getInstance()->getMcpSessionCache()),
            ))
            ->setReferenceHandler(new PermissionedReferenceHandler(
                new ReferenceHandler(),
                $this->registry,
                $this->toolContext,
            ));

        // Two filters apply: the legacy cpOnly flag hides tools that
        // never made sense outside the CP (e.g. preview-pane drivers),
        // and the per-tool ALLOWED_CLIENTS list scopes some tools to a
        // single surface (e.g. update_code_component to the
        // CodeComponent field's Prompt tab). Both run here so external
        // MCP clients never see a tool they couldn't successfully
        // execute.
        $descriptors = $this->registry->descriptors(includeCpOnly: false, onlyAllowed: true);
        $descriptors = $this->registry->filterByClient($descriptors, \markhuot\craftai\agent\ClientType::MCP);
        foreach ($descriptors as $descriptor) {
            $this->registerTool($builder, $descriptor);
        }

        return $builder->build();
    }

    private function registerTool(\Mcp\Server\Builder $builder, ToolDescriptor $descriptor): void
    {
        // mcp/sdk reflects __invoke on the class string and instantiates via
        // its container. Our tools extend the same Tool base class the agent
        // loop uses, so the same class powers both surfaces.
        //
        // toMcpTool() is the single source of truth for the MCP wire shape —
        // it handles the empty-properties → object coercion that strict
        // clients (Claude Code's Zod validator) require, and surfaces tool
        // annotations like destructiveHint/idempotentHint.
        $mcp = $descriptor->toMcpTool();

        $builder->addTool(
            handler: $descriptor->toolClass,
            name: $mcp['name'],
            description: $mcp['description'],
            annotations: isset($mcp['annotations']) ? ToolAnnotations::fromArray($mcp['annotations']) : null,
            inputSchema: $mcp['inputSchema'],
        );
    }
}
