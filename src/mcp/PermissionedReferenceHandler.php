<?php

namespace markhuot\craftai\mcp;

use Craft;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\binders\Binder;
use markhuot\craftai\permissions\ToolPermissionDeniedException;
use markhuot\craftai\tools\Tool;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;
use ReflectionMethod;

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

            // The SDK's reflection-based ReferenceHandler casts primitives and
            // forwards directly to __invoke, bypassing the #[Bind] and
            // #[Validate] attributes that ToolRegistry::execute runs on the CP
            // path. Without this, MCP calls would skip validation and tools
            // expecting bound models (e.g. an Entry instance from int id)
            // would receive raw scalars and fail their `instanceof` checks.
            $arguments = $this->applyBindersAndValidators($reference, $arguments);
        }

        $this->toolContext->begin(null, null, ClientType::MCP);
        try {
            return $this->inner->handle($reference, $arguments);
        } finally {
            $this->toolContext->end();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function applyBindersAndValidators(ToolReference $reference, array $arguments): array
    {
        if (! is_string($reference->handler) || ! class_exists($reference->handler)) {
            return $arguments;
        }

        $toolClass = $reference->handler;
        if (! method_exists($toolClass, '__invoke')) {
            return $arguments;
        }

        /** @var Tool $tool */
        $tool = Craft::$container->get($toolClass);

        // _session and _request are SDK-injected and aren't part of the
        // user-facing tool surface — exclude them from validation/binding.
        $userArgs = $arguments;
        unset($userArgs['_session'], $userArgs['_request']);

        if (($error = $tool->validate($userArgs, Tool::PHASE_UNBOUND)) instanceof ToolOutput) {
            throw new ToolCallException($error->text);
        }

        $method = new ReflectionMethod($toolClass, '__invoke');
        $bound = $userArgs;
        foreach ($method->getParameters() as $param) {
            $bindAttrs = $param->getAttributes(Bind::class);
            if ($bindAttrs === []) {
                continue;
            }

            /** @var Bind $bind */
            $bind = $bindAttrs[0]->newInstance();
            /** @var Binder $binder */
            $binder = new ($bind->binder)(...$bind->options);
            $bound[$param->getName()] = $binder->bind(
                $userArgs[$param->getName()] ?? null,
                $userArgs,
            );
        }

        if (($error = $tool->validate($bound, Tool::PHASE_BOUND)) instanceof ToolOutput) {
            throw new ToolCallException($error->text);
        }

        // Merge bound values back onto the original arguments so the SDK's
        // _session/_request entries survive untouched.
        return array_merge($arguments, $bound);
    }
}
