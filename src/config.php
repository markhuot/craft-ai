<?php

/**
 * Default config for the craft-ai plugin. Copy/override by creating
 * config/craft-ai.php in your Craft project.
 *
 * 'provider' is required when using the in-app agent loop. There is no
 * default — set it explicitly to make the choice visible at the project level.
 */
return [
    // 'anthropic' | 'openai'
    'provider' => null,

    // Provider API key. Read from env in real projects: getenv('ANTHROPIC_API_KEY')
    'apiKey' => null,

    // Provider model. Defaults are sensible per-provider; override here if needed.
    'model' => null,

    // Optional system prompt prepended to every conversation.
    'system' => null,

    // MCP HTTP endpoint: hardcoded user id used to authenticate inbound MCP
    // requests. Will be replaced with OAuth in a future iteration.
    'mcpUserId' => 1,
];
