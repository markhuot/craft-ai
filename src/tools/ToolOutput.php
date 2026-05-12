<?php

namespace markhuot\craftai\tools;

class ToolOutput
{
    /**
     * @param string $text Plain-text payload. Used as the tool_result.content
     *        when $blocks is null, and as a transcript/summary fallback when
     *        the provider can't render the structured blocks.
     * @param list<array<string, mixed>>|null $blocks Optional Anthropic-shaped
     *        content blocks (text + image). When set, the agent loop uses this
     *        as the tool_result.content array so vision-capable providers can
     *        see image bytes directly. OpenAI-compat providers strip non-text
     *        blocks at translation time (see OpenAiProvider::toolResultText).
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $isError = false,
        public readonly ?array $blocks = null,
    ) {}
}
