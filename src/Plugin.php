<?php

namespace markhuot\craftai;

use Craft;
use craft\base\Plugin as BasePlugin;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolRegistry;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    private ToolRegistry $toolRegistry;

    public static function getInstance(): static
    {
        $instance = parent::getInstance();

        if ($instance === null) {
            throw new \RuntimeException('craft-ai plugin is not installed');
        }

        return $instance;
    }

    public function init(): void
    {
        parent::init();

        $this->toolRegistry = new ToolRegistry();
        $this->toolRegistry->register(GetHealth::class);

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'markhuot\\craftai\\console\\controllers';
        }
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }
}
