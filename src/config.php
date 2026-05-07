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
];
