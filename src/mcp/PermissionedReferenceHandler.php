<?php

namespace markhuot\craftai\mcp;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use markhuot\craftai\permissions\ToolPermissionDeniedException;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Wraps an mcp/sdk ReferenceHandler with a Craft permission gate. On a
 * permission denial we throw {@see ToolCallException} which mcp/sdk surfaces
 * as a tool error result to the client. JSON-RPC over MCP multiplexes many
 * calls onto a single HTTP request, so a per-tool 401/403 isn't possible —
 * a clear permission-denied error is the closest equivalent.
 */
final class PermissionedReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
        private readonly ToolRegistry $registry,
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

        return $this->inner->handle($reference, $arguments);
    }
}
