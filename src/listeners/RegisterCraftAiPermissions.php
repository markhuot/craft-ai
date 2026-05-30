<?php

namespace markhuot\craftai\listeners;

use Craft;
use craft\events\RegisterUserPermissionsEvent;
use markhuot\craftai\fields\CodeComponentPermissions;
use markhuot\craftai\tools\ToolRegistry;

/**
 * Register a "Craft AI" permission group, one entry per registered tool
 * (so admins can grant/deny individual tools) plus the CodeComponent
 * field's own permission definitions. Reads the live {@see ToolRegistry}
 * passed in at construction so the permission list always matches whatever
 * tools were registered during plugin init (including event-contributed
 * ones).
 */
class RegisterCraftAiPermissions
{
    public function __construct(private ToolRegistry $toolRegistry) {}

    public function __invoke(RegisterUserPermissionsEvent $event): void
    {
        $permissions = [];
        foreach ($this->toolRegistry->descriptors() as $descriptor) {
            $permissions[ToolRegistry::permissionName($descriptor->name)] = [
                'label' => Craft::t('craft-ai', 'Use tool: {name}', ['name' => $descriptor->name]),
                'info' => $descriptor->description !== '' ? $descriptor->description : null,
            ];
        }

        foreach (CodeComponentPermissions::definitions() as $definition) {
            $permissions[$definition['key']] = [
                'label' => Craft::t('craft-ai', $definition['label']),
                'info' => Craft::t('craft-ai', $definition['info']),
            ];
        }

        $event->permissions[] = [
            'heading' => Craft::t('craft-ai', 'Craft AI'),
            'permissions' => $permissions,
        ];
    }
}
