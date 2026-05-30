<?php

namespace markhuot\craftai\agent\providers;

/**
 * Builds the configured {@see LlmProvider} from resolved settings.
 *
 * Both the main `LlmProvider` container binding and {@see
 * \markhuot\craftai\Plugin::getSmallModelProvider()} previously inlined the
 * same three steps — null-check the provider, null-check the apiKey, then a
 * `match` that constructs the concrete Anthropic/OpenAI client. The only thing
 * that differed between the two call sites was which model (and which built-in
 * default) to request. This collapses that shared logic into one __invoke-able
 * class (mirroring the listeners), so the call sites carry nothing but the
 * resolved settings and a `small` flag selecting the default tier.
 *
 * It lives alongside the providers rather than in `listeners\` because it's a
 * provider factory, not a Craft event handler — the __invoke shape is the
 * pattern being reused, not the listener role.
 */
class MakeLlmProvider
{
    /**
     * Built-in default models, keyed by provider then tier. Used only when the
     * site hasn't configured a model (or smallModel) — an explicit config value
     * always wins. The "small" tier picks cheaper/faster models for the
     * background summarization the small-model helper drives.
     *
     * @var array<string, array{main: string, small: string}>
     */
    private const DEFAULT_MODELS = [
        'anthropic' => ['main' => 'claude-sonnet-4-20250514', 'small' => 'claude-haiku-4-5-20251001'],
        'openai' => ['main' => 'gpt-4o', 'small' => 'gpt-4o-mini'],
    ];

    /**
     * @param  ?string  $provider  The configured provider key ("anthropic" | "openai").
     * @param  ?string  $apiKey    The provider API key.
     * @param  ?string  $model     The resolved model, or null to fall back to the built-in default.
     * @param  ?string  $baseUrl   Optional OpenAI-compatible base URL override (ignored by Anthropic).
     * @param  bool     $small     When true, fall back to the cheaper "small" default model.
     */
    public function __invoke(
        ?string $provider,
        ?string $apiKey,
        ?string $model,
        ?string $baseUrl = null,
        bool $small = false,
    ): LlmProvider {
        if ($provider === null) {
            throw new \RuntimeException('craft-ai: no provider configured. Set "provider" in config/craft-ai.php to "anthropic" or "openai".');
        }
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException("craft-ai: provider \"{$provider}\" is configured but apiKey is missing in config/craft-ai.php.");
        }

        $tier = $small ? 'small' : 'main';

        return match ($provider) {
            'anthropic' => new AnthropicProvider($apiKey, $model ?? self::DEFAULT_MODELS['anthropic'][$tier]),
            'openai' => new OpenAiProvider($apiKey, $model ?? self::DEFAULT_MODELS['openai'][$tier], baseUrl: $baseUrl),
            default => throw new \RuntimeException("craft-ai: unknown provider \"{$provider}\". Use \"anthropic\" or \"openai\"."),
        };
    }
}
