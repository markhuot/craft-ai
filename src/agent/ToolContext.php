<?php

namespace markhuot\craftai\agent;

/**
 * Request-scoped state set by the surface driving the registry (agent loop,
 * MCP server, etc.) before each tool invocation, so tools can correlate
 * themselves back to the session/turn they're running for and gate
 * behavior on which client surface they're talking to.
 *
 * Tools resolve this via Craft's DI container (registered as a singleton in
 * Plugin::registerContainerBindings) and read whichever value is current.
 * The driver pushes context with {@see begin()} immediately before calling
 * the registry and pops it again in a finally block — so a thrown exception
 * doesn't leak the previous tool's identifiers into the next one.
 *
 * Single-process queue workers run tools sequentially, so a singleton with
 * mutable state is safe here. If we ever introduce concurrent tool execution
 * in the same worker we'll need to switch to a stack or fiber-local storage.
 */
class ToolContext
{
    private ?string $sessionId = null;

    private ?string $toolUseId = null;

    private ?ClientType $client = null;

    public function begin(?string $sessionId, ?string $toolUseId, ?ClientType $client): void
    {
        $this->sessionId = $sessionId;
        $this->toolUseId = $toolUseId;
        $this->client = $client;
    }

    public function end(): void
    {
        $this->sessionId = null;
        $this->toolUseId = null;
        $this->client = null;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getToolUseId(): ?string
    {
        return $this->toolUseId;
    }

    public function getClient(): ?ClientType
    {
        return $this->client;
    }

    public function requireSessionId(): string
    {
        if ($this->sessionId === null) {
            throw new \LogicException(
                'No active session context. Tools requiring a session must be '
                .'invoked from inside AgentLoop::run, which sets the context.',
            );
        }

        return $this->sessionId;
    }
}
