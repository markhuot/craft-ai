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
];
