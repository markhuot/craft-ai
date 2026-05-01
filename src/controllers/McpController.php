<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use Mcp\Server\Transport\StreamableHttpTransport;
use markhuot\craftai\mcp\ServerFactory;
use markhuot\craftai\Plugin;
use markhuot\craftai\tools\ToolRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use yii\web\Response;

/**
 * HTTP entrypoint for the MCP server. The mcp/sdk Streamable HTTP transport
 * handles JSON-RPC + SSE on a single endpoint. Auth is currently a hardcoded
 * stub (logs in user #1) so the MCP runs in the context of a Craft user; this
 * is the seam where OAuth will plug in later.
 */
class McpController extends Controller
{
    public array|bool|int $allowAnonymous = ['handle'];

    public $enableCsrfValidation = false;

    public function actionHandle(): Response
    {
        $app = Craft::$app;
        if (! $app instanceof \craft\web\Application) {
            throw new \RuntimeException('craft-ai: MCP endpoint requires a web request context.');
        }

        $userId = Plugin::getInstance()->getSettingsArray()['mcpUserId'];
        $user = User::find()->id($userId)->one();
        if (! $user instanceof User) {
            throw new \RuntimeException("craft-ai: MCP stub user #{$userId} not found.");
        }
        $app->getUser()->setIdentity($user);

        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        $psrRequest = $creator->fromGlobals();

        /** @var ToolRegistry $registry */
        $registry = Craft::$container->get(ToolRegistry::class);
        $server = (new ServerFactory($registry))->build();

        $transport = new StreamableHttpTransport($psrRequest, $psr17, $psr17);
        $psrResponse = $server->run($transport);

        return $this->copyPsrToYii($psrResponse);
    }

    private function copyPsrToYii(ResponseInterface $psr): Response
    {
        $app = Craft::$app;
        if (! $app instanceof \craft\web\Application) {
            throw new \RuntimeException('craft-ai: MCP endpoint requires a web request context.');
        }
        $yii = $app->getResponse();
        $yii->statusCode = $psr->getStatusCode();
        foreach ($psr->getHeaders() as $name => $values) {
            $yii->getHeaders()->set($name, implode(', ', $values));
        }
        $yii->format = Response::FORMAT_RAW;
        $yii->content = (string) $psr->getBody();

        return $yii;
    }
}
