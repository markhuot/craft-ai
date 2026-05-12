<?php

namespace markhuot\craftai\tools;

use Craft;

/**
 * Check the health of the Craft CMS installation and confirm the system is operational.
 */
class GetHealth extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: array{craftVersion: string, status: string, message: string}}
     */
    public function __invoke(): array
    {
        $version = Craft::$app->getVersion();

        return [
            '_notes' => "Craft CMS {$version} is running and all systems are operational.",
            'data' => [
                'craftVersion' => $version,
                'status' => 'ok',
                'message' => "Craft CMS {$version} is running and all systems are operational.",
            ],
        ];
    }
}
