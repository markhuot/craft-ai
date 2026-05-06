<?php

namespace markhuot\craftai\agent;

/**
 * Identifies which surface a tool invocation is coming from. Set on
 * {@see ToolContext} by the entry point that drives the registry — the agent
 * loop, the MCP server, etc. — and read by tools whose behavior or output
 * differs by surface (notably the preview pane is only available in CP).
 *
 * The CP value covers the in-app Craft control panel chat (which has the
 * preview pane and can call `open_preview`/`get_preview`). WIDGET covers the
 * front-end visitor-facing chat which has no preview pane. MCP covers external
 * MCP clients, where CP-only tools are not even registered.
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
}
