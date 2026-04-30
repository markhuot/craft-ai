<?php

namespace markhuot\craftai\console\controllers;

use Craft;
use craft\console\Controller;
use Mcp\Server\Transport\StdioTransport;
use markhuot\craftai\mcp\ServerFactory;
use markhuot\craftai\tools\ToolRegistry;
use yii\console\ExitCode;

class McpController extends Controller
{
    public function actionServe(): int
    {
        /** @var ToolRegistry $registry */
        $registry = Craft::$container->get(ToolRegistry::class);
        $server = (new ServerFactory($registry))->build();
        $server->run(new StdioTransport());

        return ExitCode::OK;
    }
}
