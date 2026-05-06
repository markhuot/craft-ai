<?php

namespace markhuot\craftai\mcp;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\permissions\ToolPermissionDeniedException;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Wraps an mcp/sdk ReferenceHandler with a Craft permission gate. On a
 * permission denial we throw {@see ToolCallException} which mcp/sdk surfaces
 * as a tool error result to the client. JSON-RPC over MCP multiplexes many
 * calls onto a single HTTP request, so a per-tool 401/403 isn't possible —
 * a clear permission-denied error is the closest equivalent.
 *
 * This handler is also where we push {@see ClientType::MCP} onto the shared
 * {@see ToolContext} so any tool that branches on surface (e.g. emitting
 * preview suggestions only for CP) sees the correct value during MCP calls.
 * mcp/sdk's reflection-based handler doesn't go through ToolRegistry::execute,
 * so this is the only spot in the MCP path with both the dependency and the
 * call lifecycle to manage that state.
 */
final class PermissionedReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
        private readonly ToolRegistry $registry,
        private readonly ToolContext $toolContext = new ToolContext(),
    ) {}

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if ($reference instanceof ToolReference) {
            try {
                $this->registry->assertPermission($reference->tool->name);
            } catch (ToolPermissionDeniedException $e) {
                throw new ToolCallException($e->getMessage(), 0, $e);
            }
        }

        $this->toolContext->begin(null, null, ClientType::MCP);
        try {
            return $this->inner->handle($reference, $arguments);
        } finally {
            $this->toolContext->end();
        }
    }
}
