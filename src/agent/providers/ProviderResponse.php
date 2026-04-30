<?php

namespace markhuot\craftai\agent\providers;

/**
 * Normalized response from any LlmProvider. Content blocks use the Anthropic
 * shape (text / tool_use) since the AgentLoop, MessageRecord history, and
 * MCP all already speak that vocabulary.
 *
 * stop_reason values: 'end_turn' | 'tool_use' | 'max_tokens' | 'stop_sequence'.
 */
class ProviderResponse
{
    /**
     * @param list<array<string, mixed>> $content
     */
    public function __construct(
        public readonly string $id,
        public readonly array $content,
        public readonly string $stopReason,
    ) {}
}
