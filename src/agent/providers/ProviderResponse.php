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
     * @param array<string, mixed> $raw Full decoded provider payload, persisted
     *        with the assistant message so we can debug provider-specific
     *        fields (e.g. DeepSeek `reasoning_content`) after the fact.
     * @param int|null $inputTokens Prompt tokens reported by the provider for
     *        this request (Anthropic `usage.input_tokens`, OpenAI
     *        `usage.prompt_tokens`). Null when the provider didn't include
     *        usage in its payload.
     * @param int|null $outputTokens Completion tokens for this turn (Anthropic
     *        `usage.output_tokens`, OpenAI `usage.completion_tokens`).
     */
    public function __construct(
        public readonly string $id,
        public readonly array $content,
        public readonly string $stopReason,
        public readonly array $raw = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {}
}
