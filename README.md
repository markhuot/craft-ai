# Craft AI

Craft AI brings an AI agent into Craft CMS. It adds a control panel chat interface, an optional front-end widget for logged-in control panel users, and an MCP server so external AI clients can safely work with your Craft content and project structure.

## What it can do

Craft AI gives an LLM access to Craft-aware tools that can:

- Search and inspect entries, drafts, sections, entry types, fields, field layouts, templates, assets, and volumes.
- Create or update entries, drafts, sections, entry types, fields, field layout elements, templates, and assets.
- Delete entries, drafts, sections, entry types, fields, and assets when the current user has permission.
- Fetch webpage content for research and content assistance from inside the control panel agent.
- Attach Craft assets to chat prompts so the agent can inspect them when needed.
- Keep per-user AI sessions with conversation history, generated session titles, stop controls, and queued background processing.
- Expose the same Craft tools over MCP using either the built-in HTTP endpoint with OAuth or the console stdio server.

The in-app agent supports Anthropic and OpenAI-compatible providers. OpenAI-compatible configuration can point at alternate base URLs such as Azure OpenAI, Groq, Together, OpenRouter, Ollama, or LM Studio.

## Why install it?

Install Craft AI if you want a site-aware assistant that understands Craft concepts instead of a generic chatbot. It can help editors draft and revise content, help developers inspect and adjust Craft structures, and let external MCP clients interact with the same permission-checked tools your Craft users can access.

Because tools run as the current Craft user, you can grant access per tool through Craft permissions. Admin users automatically pass these checks, while other users can be limited to only the operations they should perform.

## Installation

Install the plugin with Composer:

```bash
composer require markhuot/craft-ai
php craft plugin/install craft-ai
```

Then visit **AI Sessions** in the Craft control panel.

## Configuration

Craft AI looks for a project config file at `config/craft-ai.php`. If the file is missing, the AI Sessions page can copy the plugin's example config into place for you.

At minimum, set a provider and API key:

```php
<?php

return [
    'provider' => 'anthropic', // or 'openai'
    'apiKey' => getenv('ANTHROPIC_API_KEY'),
];
```

Available settings include:

- `provider`: `anthropic` or `openai`.
- `apiKey`: the provider API key.
- `model`: the main chat model. Defaults are provider-specific.
- `smallModel`: an optional smaller model for lightweight tasks like session titles.
- `system`: an optional system prompt prepended to conversations.
- `baseUrl`: an optional OpenAI-compatible API base URL override.

After configuration, make sure your Craft queue is running so agent jobs can process in the background.

## Using Craft AI

### Control panel chat

Open **AI Sessions** in the control panel to start a new conversation. The chat UI supports ongoing sessions, message polling while the agent works, stopping an active run, and attaching Craft assets to a prompt.

### Front-end widget

For logged-in users with control panel access, Craft AI injects a small site widget on front-end pages. The widget reuses the same sessions and chat flow, making it easy to ask site-aware questions while browsing the site.

### MCP clients

Craft AI also exposes its tools through MCP:

- HTTP transport is available at `/mcp` and uses the plugin's OAuth endpoints for authorization.
- Stdio transport is available through the Craft console command `php craft mcp/serve`.

This lets compatible AI clients connect to your Craft project while still respecting Craft user identity and tool permissions.

## Development

This repository includes PHP and TypeScript test/build tooling:

```bash
vendor/bin/pest
vendor/bin/phpstan analyze
bun test
bun run typecheck
bun run build
```

The front-end bundles for the chat interface and widget are built with Bun.
