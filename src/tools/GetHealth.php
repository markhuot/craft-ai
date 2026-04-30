<?php

namespace markhuot\craftai\tools;

use Craft;
use markhuot\craftai\attributes\Description;

#[Description('Check the health of the Craft CMS installation and confirm the system is operational')]
class GetHealth
{
    public function __invoke(): ToolOutput
    {
        $version = Craft::$app->getVersion();

        return new ToolOutput("Craft CMS {$version} is running and all systems are operational.");
    }
}
