<?php

namespace markhuot\craftai\tools;

use Craft;

/**
 * Check the health of the Craft CMS installation and confirm the system is operational.
 */
class GetHealth extends Tool
{
    public const KIND = ToolKind::Read;

    public function __invoke(): string
    {
        $version = Craft::$app->getVersion();

        return "Craft CMS {$version} is running and all systems are operational.";
    }
}
