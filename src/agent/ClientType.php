<?php

namespace markhuot\craftai\agent;

/**
 * Identifies which surface a tool invocation is coming from. Set on
 * {@see ToolContext} by the entry point that drives the registry — the agent
 * loop, the MCP server, etc. — and read by tools whose behavior or output
 * differs by surface (notably the preview pane is only available in CP).
 *
 * Surfaces:
 *  - CP: the in-app Craft control panel chat — has the preview pane, can
 *    call `open_preview` / `get_preview`.
 *  - WIDGET: the front-end visitor-facing chat which has no preview pane.
 *  - MCP: external MCP clients, where CP-only tools are not even registered.
 *  - CODE_COMPONENT_FIELD: the embedded chat inside the CodeComponent
 *    field's Prompt tab. A strict subset of CP — tools that only make sense
 *    in that authoring surface (e.g. `update_code_component`) declare it
 *    here via {@see Tool::ALLOWED_CLIENTS}.
 *
 * Console / queue / test invocations that don't go through any of these entry
 * points leave the context's client as null. Tools that gate behavior on the
 * client should treat null as "not CP" — i.e. as conservative as MCP.
 */
enum ClientType: string
{
    case CP = 'cp';
    case WIDGET = 'widget';
    case MCP = 'mcp';
    case CODE_COMPONENT_FIELD = 'code-component-field';
}
