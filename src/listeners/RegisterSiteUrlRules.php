<?php

namespace markhuot\craftai\listeners;

use craft\events\RegisterUrlRulesEvent;

/**
 * Register the plugin's front-end (site) URL rules — the MCP transport
 * endpoint and the OAuth authorization-server / protected-resource
 * metadata + token endpoints that an MCP client walks during connect.
 */
class RegisterSiteUrlRules
{
    public function __invoke(RegisterUrlRulesEvent $event): void
    {
        $event->rules['POST mcp'] = 'craft-ai/mcp/handle';
        $event->rules['GET mcp'] = 'craft-ai/mcp/handle';
        $event->rules['DELETE mcp'] = 'craft-ai/mcp/handle';
        $event->rules['OPTIONS mcp'] = 'craft-ai/mcp/handle';

        $event->rules['GET .well-known/oauth-authorization-server'] = 'craft-ai/oauth/authorization-server-metadata';
        $event->rules['GET .well-known/oauth-authorization-server/<resourcePath:.*>'] = 'craft-ai/oauth/authorization-server-metadata';
        $event->rules['GET .well-known/oauth-protected-resource'] = 'craft-ai/oauth/protected-resource-metadata';
        $event->rules['GET .well-known/oauth-protected-resource/<resourcePath:.*>'] = 'craft-ai/oauth/protected-resource-metadata';
        $event->rules['POST craft-ai/oauth/register'] = 'craft-ai/oauth/register';
        $event->rules['GET craft-ai/oauth/authorize'] = 'craft-ai/oauth/authorize';
        $event->rules['POST craft-ai/oauth/authorize'] = 'craft-ai/oauth/approve';
        $event->rules['POST craft-ai/oauth/token'] = 'craft-ai/oauth/token';
    }
}
