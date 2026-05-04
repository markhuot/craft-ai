<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\records\OauthAuthCodeRecord;
use markhuot\craftai\records\OauthClientRecord;
use markhuot\craftai\records\OauthTokenRecord;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * OAuth 2.1 endpoints for incoming MCP SSE clients.
 *
 * Implements:
 *   - RFC 8414 Authorization Server Metadata
 *   - RFC 9728 Protected Resource Metadata
 *   - RFC 7591 Dynamic Client Registration
 *   - Authorization Code grant with PKCE (S256)
 *   - Refresh token grant
 */
class OauthController extends Controller
{
    public array|bool|int $allowAnonymous = [
        'authorization-server-metadata',
        'protected-resource-metadata',
        'register',
        'token',
        'authorize',
        'approve',
    ];

    public $enableCsrfValidation = false;

    private const ACCESS_TOKEN_TTL = 3600;
    private const REFRESH_TOKEN_TTL = 60 * 60 * 24 * 30;
    private const AUTH_CODE_TTL = 600;

    public function actionAuthorizationServerMetadata(): Response
    {
        $issuer = rtrim(UrlHelper::siteUrl(''), '/');

        return $this->asJson([
            'issuer' => $issuer,
            'authorization_endpoint' => UrlHelper::siteUrl('craft-ai/oauth/authorize'),
            'token_endpoint' => UrlHelper::siteUrl('craft-ai/oauth/token'),
            'registration_endpoint' => UrlHelper::siteUrl('craft-ai/oauth/register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'scopes_supported' => ['mcp'],
        ]);
    }

    public function actionProtectedResourceMetadata(?string $resourcePath = null): Response
    {
        $issuer = rtrim(UrlHelper::siteUrl(''), '/');
        $path = $resourcePath !== null && $resourcePath !== '' ? $resourcePath : 'mcp';

        return $this->asJson([
            'resource' => UrlHelper::siteUrl($path),
            'authorization_servers' => [$issuer],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
        ]);
    }

    public function actionRegister(): Response
    {
        $this->requirePostRequest();

        $body = $this->parseJsonBody();

        $redirectUris = $body['redirect_uris'] ?? null;
        if (! is_array($redirectUris) || $redirectUris === []) {
            throw new BadRequestHttpException('redirect_uris is required.');
        }
        foreach ($redirectUris as $uri) {
            if (! is_string($uri) || ! $this->isValidRedirectUri($uri)) {
                throw new BadRequestHttpException('redirect_uris contains an invalid value.');
            }
        }

        $grantTypes = $body['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (! is_array($grantTypes)) {
            throw new BadRequestHttpException('grant_types must be an array.');
        }
        $allowedGrants = ['authorization_code', 'refresh_token'];
        $normalizedGrants = [];
        foreach ($grantTypes as $g) {
            if (! is_string($g) || ! in_array($g, $allowedGrants, true)) {
                throw new BadRequestHttpException('grant_types contains an unsupported value.');
            }
            $normalizedGrants[] = $g;
        }
        $grantTypes = $normalizedGrants;

        $authMethod = $body['token_endpoint_auth_method'] ?? 'none';
        if (! is_string($authMethod) || ! in_array($authMethod, ['none', 'client_secret_basic', 'client_secret_post'], true)) {
            throw new BadRequestHttpException('Unsupported token_endpoint_auth_method.');
        }

        $clientId = 'cai_'.bin2hex(random_bytes(16));
        $clientSecret = null;
        $clientSecretHash = null;
        if ($authMethod !== 'none') {
            $clientSecret = bin2hex(random_bytes(32));
            $clientSecretHash = password_hash($clientSecret, PASSWORD_BCRYPT);
        }

        $record = new OauthClientRecord();
        $record->clientId = $clientId;
        $record->clientSecretHash = $clientSecretHash;
        $clientName = $body['client_name'] ?? null;
        $record->clientName = is_string($clientName) ? $clientName : 'MCP Client';
        $redirectUrisJson = json_encode(array_values($redirectUris), JSON_THROW_ON_ERROR);
        $grantTypesJson = json_encode($grantTypes, JSON_THROW_ON_ERROR);
        $record->redirectUris = $redirectUrisJson;
        $record->grantTypes = $grantTypesJson;
        $record->tokenEndpointAuthMethod = $authMethod;
        $bodyScope = $body['scope'] ?? null;
        $record->scope = is_string($bodyScope) ? $bodyScope : 'mcp';
        $record->save(false);

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $record->clientName,
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'token_endpoint_auth_method' => $authMethod,
            'scope' => $record->scope,
        ];
        if ($clientSecret !== null) {
            $response['client_secret'] = $clientSecret;
            $response['client_secret_expires_at'] = 0;
        }

        $this->response->setStatusCode(201);

        return $this->asJson($response);
    }

    public function actionAuthorize(): Response
    {
        $responseType = $this->stringQuery('response_type');
        $clientId = $this->stringQuery('client_id');
        $redirectUri = $this->stringQuery('redirect_uri');
        $state = $this->stringQuery('state');
        $scope = $this->stringQuery('scope');
        $codeChallenge = $this->stringQuery('code_challenge');
        $codeChallengeMethod = $this->stringQuery('code_challenge_method', 'S256');

        if ($responseType !== 'code') {
            throw new BadRequestHttpException('Only response_type=code is supported.');
        }
        if ($clientId === '' || $redirectUri === '' || $codeChallenge === '') {
            throw new BadRequestHttpException('client_id, redirect_uri, and code_challenge are required.');
        }
        if ($codeChallengeMethod !== 'S256') {
            throw new BadRequestHttpException('Only S256 code_challenge_method is supported.');
        }

        $client = OauthClientRecord::findOne(['clientId' => $clientId]);
        if (! $client instanceof OauthClientRecord) {
            throw new BadRequestHttpException('Unknown client_id.');
        }
        if (! $this->isClientRedirectUri($client, $redirectUri)) {
            throw new BadRequestHttpException('redirect_uri is not registered for this client.');
        }

        $userComponent = Craft::$app->getUser();
        if ($userComponent->getIsGuest()) {
            $loginUrl = Craft::$app instanceof \craft\web\Application
                ? Craft::$app->getUser()->loginUrl
                : null;
            $base = is_string($loginUrl) ? $loginUrl : UrlHelper::cpUrl('login');

            return $this->redirect($base.'?'.http_build_query([
                'returnUrl' => $this->request->getUrl(),
            ]));
        }

        $view = Craft::$app->getView();
        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
        try {
            $html = $view->renderTemplate('craft-ai/oauth/consent', [
                'client' => $client,
                'currentUser' => Craft::$app->getUser()->getIdentity(),
                'params' => [
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                    'state' => $state,
                    'scope' => $scope === '' ? ($client->scope ?? 'mcp') : $scope,
                    'code_challenge' => $codeChallenge,
                    'code_challenge_method' => $codeChallengeMethod,
                ],
            ]);
        } finally {
            $view->setTemplateMode($previousMode);
        }

        $this->response->format = Response::FORMAT_HTML;
        $this->response->content = $html;

        return $this->response;
    }

    public function actionApprove(): Response
    {
        $this->requirePostRequest();

        $clientId = $this->stringBody('client_id', required: true);
        $redirectUri = $this->stringBody('redirect_uri', required: true);
        $state = $this->stringBody('state');
        $scope = $this->stringBody('scope');
        $codeChallenge = $this->stringBody('code_challenge', required: true);
        $codeChallengeMethod = $this->stringBody('code_challenge_method', 'S256');
        $decision = $this->stringBody('decision', 'deny');

        $client = OauthClientRecord::findOne(['clientId' => $clientId]);
        if (! $client instanceof OauthClientRecord) {
            throw new BadRequestHttpException('Unknown client_id.');
        }
        if (! $this->isClientRedirectUri($client, $redirectUri)) {
            throw new BadRequestHttpException('redirect_uri is not registered for this client.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            throw new BadRequestHttpException('Login required.');
        }

        if ($decision !== 'allow') {
            return $this->redirect($this->appendQuery($redirectUri, [
                'error' => 'access_denied',
                'state' => $state,
            ]));
        }

        $code = bin2hex(random_bytes(32));
        $record = new OauthAuthCodeRecord();
        $record->code = $code;
        $record->clientId = $clientId;
        $record->userId = (int) $user->id;
        $record->redirectUri = $redirectUri;
        $record->scope = $scope !== '' ? $scope : null;
        $record->codeChallenge = $codeChallenge;
        $record->codeChallengeMethod = $codeChallengeMethod;
        $record->expiresAt = gmdate('Y-m-d H:i:s', time() + self::AUTH_CODE_TTL);
        $record->consumed = false;
        $record->save(false);

        return $this->redirect($this->appendQuery($redirectUri, [
            'code' => $code,
            'state' => $state,
        ]));
    }

    public function actionToken(): Response
    {
        $this->requirePostRequest();

        $grantType = $this->stringBody('grant_type');

        return match ($grantType) {
            'authorization_code' => $this->grantAuthorizationCode(),
            'refresh_token' => $this->grantRefreshToken(),
            default => $this->tokenError('unsupported_grant_type', 'Unsupported grant_type.', 400),
        };
    }

    private function grantAuthorizationCode(): Response
    {
        $code = $this->stringBody('code');
        $redirectUri = $this->stringBody('redirect_uri');
        $codeVerifier = $this->stringBody('code_verifier');

        if ($code === '' || $redirectUri === '' || $codeVerifier === '') {
            return $this->tokenError('invalid_request', 'code, redirect_uri, and code_verifier are required.', 400);
        }

        $record = OauthAuthCodeRecord::findOne(['code' => $code]);
        if (! $record instanceof OauthAuthCodeRecord || (bool) $record->consumed) {
            return $this->tokenError('invalid_grant', 'Authorization code is invalid.', 400);
        }
        if (strtotime((string) $record->expiresAt.' UTC') < time()) {
            return $this->tokenError('invalid_grant', 'Authorization code has expired.', 400);
        }
        if ($record->redirectUri !== $redirectUri) {
            return $this->tokenError('invalid_grant', 'redirect_uri mismatch.', 400);
        }

        $client = OauthClientRecord::findOne(['clientId' => $record->clientId]);
        if (! $client instanceof OauthClientRecord) {
            return $this->tokenError('invalid_client', 'Unknown client.', 401);
        }
        if (! $this->authenticateClient($client)) {
            return $this->tokenError('invalid_client', 'Client authentication failed.', 401);
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (! hash_equals($record->codeChallenge, $expected)) {
            return $this->tokenError('invalid_grant', 'PKCE verification failed.', 400);
        }

        $record->consumed = true;
        $record->save(false);

        return $this->issueTokens($client, (int) $record->userId, $record->scope);
    }

    private function grantRefreshToken(): Response
    {
        $refreshToken = $this->stringBody('refresh_token');
        if ($refreshToken === '') {
            return $this->tokenError('invalid_request', 'refresh_token is required.', 400);
        }

        $record = OauthTokenRecord::findOne(['refreshToken' => $refreshToken]);
        if (! $record instanceof OauthTokenRecord || (bool) $record->revoked) {
            return $this->tokenError('invalid_grant', 'Refresh token is invalid.', 400);
        }
        if ($record->refreshExpiresAt !== null && strtotime((string) $record->refreshExpiresAt.' UTC') < time()) {
            return $this->tokenError('invalid_grant', 'Refresh token has expired.', 400);
        }

        $client = OauthClientRecord::findOne(['clientId' => $record->clientId]);
        if (! $client instanceof OauthClientRecord) {
            return $this->tokenError('invalid_client', 'Unknown client.', 401);
        }
        if (! $this->authenticateClient($client)) {
            return $this->tokenError('invalid_client', 'Client authentication failed.', 401);
        }

        $record->revoked = true;
        $record->save(false);

        return $this->issueTokens($client, (int) $record->userId, $record->scope);
    }

    private function issueTokens(OauthClientRecord $client, int $userId, ?string $scope): Response
    {
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $now = time();

        $token = new OauthTokenRecord();
        $token->accessToken = $accessToken;
        $token->refreshToken = $refreshToken;
        $token->clientId = $client->clientId;
        $token->userId = $userId;
        $token->scope = $scope;
        $token->accessExpiresAt = gmdate('Y-m-d H:i:s', $now + self::ACCESS_TOKEN_TTL);
        $token->refreshExpiresAt = gmdate('Y-m-d H:i:s', $now + self::REFRESH_TOKEN_TTL);
        $token->revoked = false;
        $token->save(false);

        $payload = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $refreshToken,
        ];
        if ($scope !== null && $scope !== '') {
            $payload['scope'] = $scope;
        }

        $response = $this->asJson($payload);
        $response->getHeaders()->set('Cache-Control', 'no-store');
        $response->getHeaders()->set('Pragma', 'no-cache');

        return $response;
    }

    private function authenticateClient(OauthClientRecord $client): bool
    {
        if ($client->tokenEndpointAuthMethod === 'none') {
            return true;
        }

        $clientId = null;
        $clientSecret = '';

        $authHeader = $this->request->getHeaders()->get('Authorization');
        if (is_string($authHeader) && stripos($authHeader, 'Basic ') === 0) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$basicId, $basicSecret] = explode(':', $decoded, 2);
                $clientId = urldecode($basicId);
                $clientSecret = urldecode($basicSecret);
            }
        }
        if ($clientId === null) {
            $clientId = $this->stringBody('client_id');
            $clientSecret = $this->stringBody('client_secret');
        }

        if ($clientId !== $client->clientId) {
            return false;
        }
        if ($client->clientSecretHash === null || $clientSecret === '') {
            return false;
        }

        return password_verify($clientSecret, $client->clientSecretHash);
    }

    private function tokenError(string $error, string $description, int $status): Response
    {
        $this->response->setStatusCode($status);
        $response = $this->asJson([
            'error' => $error,
            'error_description' => $description,
        ]);
        $response->getHeaders()->set('Cache-Control', 'no-store');
        $response->getHeaders()->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonBody(): array
    {
        $raw = $this->request->getRawBody();
        if ($raw === '') {
            $params = $this->request->getBodyParams();
            $out = [];
            foreach ($params as $key => $value) {
                $out[(string) $key] = $value;
            }

            return $out;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Request body must be valid JSON.');
        }
        if (! is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private function stringQuery(string $name, string $default = ''): string
    {
        $value = $this->request->getQueryParam($name);
        if ($value === null) {
            return $default;
        }
        if (! is_string($value)) {
            throw new BadRequestHttpException("Query parameter {$name} must be a string.");
        }

        return $value;
    }

    private function stringBody(string $name, string $default = '', bool $required = false): string
    {
        $value = $this->request->getBodyParam($name);
        if ($value === null) {
            if ($required) {
                throw new BadRequestHttpException("Body parameter {$name} is required.");
            }

            return $default;
        }
        if (! is_string($value)) {
            throw new BadRequestHttpException("Body parameter {$name} must be a string.");
        }

        return $value;
    }

    private function isValidRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme'])) {
            return false;
        }
        if (isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme === 'http') {
            $host = strtolower((string) ($parts['host'] ?? ''));

            return $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]';
        }

        return $scheme === 'https' || $scheme !== '' && ! in_array($scheme, ['ftp', 'file', 'data', 'javascript'], true);
    }

    private function isClientRedirectUri(OauthClientRecord $client, string $uri): bool
    {
        try {
            $uris = json_decode($client->redirectUris, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (! is_array($uris)) {
            return false;
        }

        return in_array($uri, $uris, true);
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $params = array_filter($params, static fn (string $v): bool => $v !== '');
        if ($params === []) {
            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.http_build_query($params);
    }
}
