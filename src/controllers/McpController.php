<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Mcp\Server\Transport\StreamableHttpTransport;
use markhuot\craftai\mcp\ServerFactory;
use markhuot\craftai\records\OauthTokenRecord;
use markhuot\craftai\tools\ToolRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use yii\web\Response;

/**
 * HTTP entrypoint for the MCP server. The mcp/sdk Streamable HTTP transport
 * handles JSON-RPC + SSE on a single endpoint. Requests must carry a
 * Bearer access token issued by the plugin's OAuth endpoints; the token's
 * userId determines which Craft user the MCP session runs as.
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

        $token = $this->extractBearerToken();
        if ($token === null) {
            return $this->unauthorized('invalid_token', 'Bearer token required.');
        }

        $record = OauthTokenRecord::findOne(['accessToken' => $token]);
        if (! $record instanceof OauthTokenRecord
            || (bool) $record->revoked
            || strtotime((string) $record->accessExpiresAt.' UTC') < time()
        ) {
            return $this->unauthorized('invalid_token', 'Access token is invalid or expired.');
        }

        $user = User::find()->id((int) $record->userId)->one();
        if (! $user instanceof User) {
            return $this->unauthorized('invalid_token', 'Token is not associated with a valid user.');
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

    private function extractBearerToken(): ?string
    {
        $header = $this->request->getHeaders()->get('Authorization');
        if (! is_string($header) || stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    private function unauthorized(string $error, string $description): Response
    {
        $pathInfo = ltrim($this->request->getPathInfo(), '/');
        $resourceMetadata = UrlHelper::siteUrl('.well-known/oauth-protected-resource/'.$pathInfo);
        $challenge = sprintf(
            'Bearer realm="craft-ai", error="%s", error_description="%s", resource_metadata="%s"',
            $error,
            $description,
            $resourceMetadata,
        );

        $response = $this->asJson([
            'error' => $error,
            'error_description' => $description,
        ]);
        $response->setStatusCode(401);
        $response->getHeaders()->set('WWW-Authenticate', $challenge);

        return $response;
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
