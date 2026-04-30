<?php

namespace markhuot\craftai\console\controllers;

use craft\console\Controller;
use markhuot\craftai\mcp\McpServer;
use markhuot\craftai\Plugin;
use yii\console\ExitCode;

class McpController extends Controller
{
    public function actionServe(): int
    {
        $registry = Plugin::getInstance()->getToolRegistry();
        $server = new McpServer($registry);
        $server->run();

        return ExitCode::OK;
    }
}
