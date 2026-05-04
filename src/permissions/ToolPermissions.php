<?php

namespace markhuot\craftai\permissions;

use markhuot\craftai\tools\ToolDescriptor;

/**
 * Helpers for tool-level Craft user permissions.
 *
 * Each tool gets a permission `craftAi:useTool:<tool_name>` (e.g.
 * `craftAi:useTool:get_entries`). Admins automatically pass.
 */
class ToolPermissions
{
    public const PREFIX = 'craftAi:useTool:';

    public static function name(string $toolName): string
    {
        return self::PREFIX.$toolName;
    }

    public static function nameForDescriptor(ToolDescriptor $descriptor): string
    {
        return self::name($descriptor->name);
    }
}
