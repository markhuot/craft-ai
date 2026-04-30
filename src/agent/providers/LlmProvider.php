<?php

namespace markhuot\craftai\agent\providers;

use markhuot\craftai\tools\ToolDescriptor;

interface LlmProvider
{
    /**
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages Anthropic-shaped message history
     * @param list<ToolDescriptor> $tools
     */
    public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse;
}
