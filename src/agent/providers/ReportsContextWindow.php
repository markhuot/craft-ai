<?php

namespace markhuot\craftai\agent\providers;

/**
 * Optional capability for an {@see LlmProvider} that can report the configured
 * model's context window straight from the provider API, so the chat gauge and
 * auto-compaction don't have to guess.
 *
 * Kept separate from {@see LlmProvider} so the dozens of message-only test
 * doubles (and any third-party provider) don't have to implement it — callers
 * feature-detect with `instanceof` and fall back when it's absent.
 *
 * The first-party OpenAI and Anthropic APIs don't expose a context-window
 * field anywhere (the message response only reports tokens *consumed*, and
 * their `/models` listings omit the ceiling), so only the OpenAI-compatible
 * client implements this — OpenAI-compatible gateways such as OpenRouter and
 * opencode.ai's zen endpoint report `context_length` on `GET /models`.
 */
interface ReportsContextWindow
{
    /**
     * The configured model's maximum context window in tokens as reported by
     * the provider's API, or null when it can't be determined. Implementations
     * make a network call, so callers should cache the result.
     */
    public function contextWindow(): ?int;
}
