<?php

/**
 * Example config for the craft-ai plugin. Copy this file to your project's
 * `config/craft-ai.php` and adjust as needed. Every available setting is
 * shown below with its default value.
 *
 * `provider` is required when using the in-app agent loop. There is no
 * default — set it explicitly to make the choice visible at the project level.
 */
return [
    // 'anthropic' | 'openai'
    'provider' => null,

    // Provider API key. Read from env in real projects: getenv('OPENAI_API_KEY').
    'apiKey' => null,

    // Provider model. Defaults are sensible per-provider; override here if needed.
    // Anthropic default: 'claude-sonnet-4-20250514'. OpenAI default: 'gpt-4o'.
    'model' => null,

    // Smaller / cheaper model used for lightweight tasks like generating
    // session titles. Falls back to `model` when null.
    // Anthropic suggested: 'claude-haiku-4-5-20251001'. OpenAI suggested: 'gpt-4o-mini'.
    'smallModel' => null,

    // Optional system prompt prepended to every conversation.
    'system' => null,

    // Optional override for the provider's HTTP base URL. Useful for pointing the
    // OpenAI provider at OpenAI-compatible endpoints (Azure OpenAI, Groq, Together,
    // OpenRouter, Ollama, LM Studio, etc.). Example: 'https://api.groq.com/openai'
    'baseUrl' => null,

    // Maximum number of tokens this model can accept in a single request.
    // Drives the chat UI's "context used" gauge and the auto-compaction
    // trigger — when a conversation passes ~95% of this value, the plugin
    // summarizes prior turns and replaces them with a single summary message
    // so the conversation can continue without hitting the provider's hard
    // limit. Leave null to use the per-provider/model defaults; set explicitly
    // for self-hosted or non-OpenAI/Anthropic models. Examples:
    //   200000 — Anthropic Claude (Sonnet, Haiku, Opus)
    //   128000 — OpenAI GPT-4o / GPT-4o-mini
    //  1048576 — DeepSeek via opencode.ai zen
    'contextWindow' => null,

    // Image generation providers. Each key registered here adds the matching
    // tool to the agent's tool list — leave a key out to keep its tool hidden
    // from the model entirely. Multiple providers may be enabled at once; the
    // agent will choose between the registered tools based on the prompt.
    //
    // Tools registered:
    //   'openai' => generate_image_gpt_image (gpt-image-1 / dall-e-3)
    //   'gemini' => generate_image_nano_banana (gemini-2.5-flash-image)
    'imageProviders' => [
        // 'openai' => [
        //     // OpenAI API key. Read from env in real projects:
        //     // getenv('OPENAI_API_KEY').
        //     'apiKey' => null,
        //
        //     // Optional base URL override. Most users leave this null and
        //     // hit api.openai.com directly. Image generation is OpenAI-only;
        //     // there is no equivalent on Azure / Groq / etc., so a baseUrl
        //     // override here only makes sense for self-hosted compat shims.
        //     'baseUrl' => null,
        // ],

        // 'gemini' => [
        //     // Google AI Studio / Gemini API key. Read from env in real
        //     // projects: getenv('GEMINI_API_KEY').
        //     'apiKey' => null,
        //
        //     // Gemini image-capable model. 'gemini-2.5-flash-image' (the
        //     // "Nano Banana" model) is the default and current GA model.
        //     'model' => 'gemini-2.5-flash-image',
        //
        //     // Optional base URL override. Most users leave this null and
        //     // hit generativelanguage.googleapis.com directly.
        //     'baseUrl' => null,
        // ],
    ],

    // Web search providers powering the `search_the_web` tool. Both backends
    // are keyless HTML scrapers, so the tool is registered by default with
    // `brave` as the primary backend and `duckduckgo` available as a fallback
    // the agent can pick via the tool's `provider` argument.
    //
    // (Note: Google and Bing both moved to JS-only rendering in 2025 and can
    //  no longer be scraped without a headless browser. Brave Search runs its
    //  own independent index and is the highest-quality keyless option that
    //  still serves server-rendered HTML.)
    //
    //   'brave'      => parses search.brave.com. Independent index, high-
    //                   quality results, server-rendered HTML. Brave also
    //                   offers a paid JSON API (api.search.brave.com) if you
    //                   need more reliability than scraping provides.
    //   'duckduckgo' => parses html.duckduckgo.com. Stable, lower-quality
    //                   results. A good fallback.
    //
    // The block below is documented for completeness; you only need it if
    // you want to change the default backend, override a baseUrl, or turn
    // the tool off entirely.
    //
    // To disable the tool entirely (so the agent can't call it), set
    // `searchProviders` to null:
    //
    //   'searchProviders' => null,
    //
    // Or, equivalently, set the default to null inside the block:
    //
    //   'searchProviders' => ['default' => null],
    //
    // 'searchProviders' => [
    //     // Which provider answers a `search_the_web` call that omits the
    //     // `provider` argument. Defaults to 'brave'.
    //     'default' => 'brave',
    //
    //     // Per-provider config blocks are optional. Provide them only when
    //     // you want to override a default (e.g. point at a different
    //     // Brave Search region/locale or self-hosted shim).
    //     'brave' => [
    //         // Optional base URL override (defaults to https://search.brave.com).
    //         'baseUrl' => null,
    //     ],
    //     'duckduckgo' => [
    //         // Optional base URL override for testing / self-hosted shims
    //         // that emulate the same HTML response shape.
    //         'baseUrl' => null,
    //     ],
    // ],
];
