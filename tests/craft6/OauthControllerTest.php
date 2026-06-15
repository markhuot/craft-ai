<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\records\OauthAuthCodeRecord;
use markhuot\craftai\records\OauthClientRecord;
use markhuot\craftai\records\OauthTokenRecord;

beforeEach(function () {
    // Existing tests rely on the admin identity established in TestCase::setUp.
    // The OAuth flow needs a real user row to bind authorization codes to, so
    // make sure that identity has a username/email like the rest of the suite.
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    Craft::$app->getUser()->loginByUserId((int) $user->id);

    // The OAuth tables come from a follow-up migration, not Install.php. In
    // some test environments the install has already run (so the migration
    // history records them as applied) but the tables themselves were dropped
    // / never created. Bootstrap them on-demand here so this file is
    // self-contained.
    ensureOauthTables();
});

/**
 * Apply the m260503 OAuth schema if the tables are missing. Idempotent.
 */
function ensureOauthTables(): void
{
    $db = Craft::$app->getDb();
    if ($db->getSchema()->getTableSchema('{{%craftai_oauth_clients}}', true) !== null) {
        return;
    }

    $migration = new \markhuot\craftai\migrations\m260503_000001_create_oauth_tables();
    $migration->db = $db;
    $migration->safeUp();
}

/**
 * POST to an OAuth endpoint that reads `parseJsonBody` (e.g. /register).
 * `$action` is the controller action route (e.g. craft-ai/oauth/register);
 * it rides in the `?action=` query param while the JSON payload is the body.
 */
function postOauthJson(string $action, array $payload) {
    return test()->postJson('?action='.$action, $payload);
}

/**
 * POST to an OAuth endpoint that reads body params via getBodyParam (e.g.
 * /token, /authorize POST). Form-encoded body; the controller action rides
 * in the `?action=` query param.
 */
function postOauthForm(string $action, array $payload) {
    return test()->post('?action='.$action, $payload);
}

/**
 * Mint a client row directly so we can exercise the token + authorize flows
 * without re-driving the public registration endpoint each time. Mirrors what
 * actionRegister would persist for `token_endpoint_auth_method = none` (the
 * default for native MCP clients). Returns the clientId so callers can build
 * subsequent requests against it.
 */
function makeOauthClient(array $attrs = []): string
{
    $client = new OauthClientRecord();
    $client->clientId = $attrs['clientId'] ?? 'cai_'.bin2hex(random_bytes(8));
    $client->clientName = $attrs['clientName'] ?? 'Test Client';
    $client->redirectUris = json_encode($attrs['redirectUris'] ?? ['http://localhost/cb'], JSON_THROW_ON_ERROR);
    $client->grantTypes = json_encode($attrs['grantTypes'] ?? ['authorization_code', 'refresh_token'], JSON_THROW_ON_ERROR);
    $client->tokenEndpointAuthMethod = $attrs['tokenEndpointAuthMethod'] ?? 'none';
    $client->clientSecretHash = $attrs['clientSecretHash'] ?? null;
    $client->scope = $attrs['scope'] ?? 'mcp';
    $client->save(false);

    return $client->clientId;
}

/**
 * PKCE helper: generate a verifier + the S256-encoded challenge the auth
 * code grant will check against. Mirrors what an MCP client computes
 * locally before redirecting the user to /authorize.
 *
 * @return array{verifier: string, challenge: string}
 */
function pkcePair(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return ['verifier' => $verifier, 'challenge' => $challenge];
}

it('registers a public MCP client and returns its identifier', function () {
    $response = postOauthJson('craft-ai/oauth/register', [
        'redirect_uris' => ['http://localhost:3000/callback'],
        'client_name' => 'MCP Inspector',
    ]);

    $response->assertStatus(201);
    $payload = $response->json();

    expect($payload['client_id'])->toStartWith('cai_');
    expect($payload['client_name'])->toBe('MCP Inspector');
    expect($payload['redirect_uris'])->toBe(['http://localhost:3000/callback']);
    expect($payload['token_endpoint_auth_method'])->toBe('none');

    // Public clients must never receive a secret — only `client_secret_basic`
    // / `client_secret_post` flows mint one.
    expect($payload)->not->toHaveKey('client_secret');

    $record = OauthClientRecord::findOne(['clientId' => $payload['client_id']]);
    expect($record)->not->toBeNull();
    expect($record->clientSecretHash)->toBeNull();
});

it('mints a hashed secret for confidential clients', function () {
    $response = postOauthJson('craft-ai/oauth/register', [
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'client_secret_basic',
    ]);

    $response->assertStatus(201);
    $payload = $response->json();

    expect($payload)->toHaveKey('client_secret');
    expect($payload['client_secret'])->toBeString();
    expect(strlen($payload['client_secret']))->toBeGreaterThan(20);

    $record = OauthClientRecord::findOne(['clientId' => $payload['client_id']]);
    // Server must hash before storing — never the raw value.
    expect($record->clientSecretHash)->not->toBe($payload['client_secret']);
    expect(password_verify($payload['client_secret'], $record->clientSecretHash))->toBeTrue();
});

it('rejects registration without redirect_uris', function () {
    $this->withoutExceptionHandling();
    $threw = false;
    try {
        postOauthJson('craft-ai/oauth/register', []);
    } catch (\yii\web\BadRequestHttpException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('rejects registration with a non-http(s) redirect uri scheme', function () {
    $this->withoutExceptionHandling();
    $threw = false;
    try {
        postOauthJson('craft-ai/oauth/register', [
            'redirect_uris' => ['javascript:alert(1)'],
        ]);
    } catch (\yii\web\BadRequestHttpException) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
});

it('exchanges a valid authorization code (PKCE) for an access + refresh token', function () {
    $clientId = makeOauthClient(['clientId' => 'cai_token_happy']);
    $pkce = pkcePair();

    // Stage the code the front-end would have minted via /approve.
    $code = bin2hex(random_bytes(16));
    $auth = new OauthAuthCodeRecord();
    $auth->code = $code;
    $auth->clientId = $clientId;
    $auth->userId = 1;
    $auth->redirectUri = 'http://localhost/cb';
    $auth->scope = 'mcp';
    $auth->codeChallenge = $pkce['challenge'];
    $auth->codeChallengeMethod = 'S256';
    $auth->expiresAt = gmdate('Y-m-d H:i:s', time() + 300);
    $auth->consumed = false;
    $auth->save(false);

    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://localhost/cb',
        'code_verifier' => $pkce['verifier'],
    ]);

    $response->assertOk();
    $payload = $response->json();

    expect($payload['token_type'])->toBe('Bearer');
    expect($payload['access_token'])->toBeString();
    expect($payload['refresh_token'])->toBeString();
    expect($payload['expires_in'])->toBeInt();
    expect($payload['scope'])->toBe('mcp');

    // The Cache-Control / Pragma headers protect tokens from intermediary caches.
    // A regression that drops them would leak bearer tokens into reverse-proxy logs.
    // Laravel's response pipeline merges its own directives in alongside the
    // controller's `no-store` (e.g. "must-revalidate, no-cache, no-store,
    // private"), so assert the token-protecting directive is present rather than
    // pinning the exact header value.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Pragma'))->toBe('no-cache');

    // Authorization codes are one-shot — the row must be marked consumed so
    // replaying the same code can't mint a second token pair.
    $reloaded = OauthAuthCodeRecord::findOne(['code' => $code]);
    expect((bool) $reloaded->consumed)->toBeTrue();

    // The minted token row should be queryable by the access token the
    // client received.
    $token = OauthTokenRecord::findOne(['accessToken' => $payload['access_token']]);
    expect($token)->not->toBeNull();
    expect($token->clientId)->toBe($clientId);
    expect((int) $token->userId)->toBe(1);
});

it('rejects a token exchange with a mismatched PKCE verifier', function () {
    $clientId = makeOauthClient(['clientId' => 'cai_token_pkce']);
    $pkce = pkcePair();
    $other = pkcePair();

    $code = bin2hex(random_bytes(16));
    $auth = new OauthAuthCodeRecord();
    $auth->code = $code;
    $auth->clientId = $clientId;
    $auth->userId = 1;
    $auth->redirectUri = 'http://localhost/cb';
    $auth->codeChallenge = $pkce['challenge'];
    $auth->codeChallengeMethod = 'S256';
    $auth->expiresAt = gmdate('Y-m-d H:i:s', time() + 300);
    $auth->consumed = false;
    $auth->save(false);

    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://localhost/cb',
        'code_verifier' => $other['verifier'],
    ]);

    $response->assertStatus(400);
    $payload = $response->json();
    expect($payload['error'])->toBe('invalid_grant');

    // The auth code must remain unconsumed so the client can retry with
    // the correct verifier — failing the PKCE check shouldn't burn the
    // code (otherwise a single typo locks the user out).
    $reloaded = OauthAuthCodeRecord::findOne(['code' => $code]);
    expect((bool) $reloaded->consumed)->toBeFalse();
});

it('rejects a token exchange with an expired authorization code', function () {
    $clientId = makeOauthClient(['clientId' => 'cai_token_expired']);
    $pkce = pkcePair();

    $code = bin2hex(random_bytes(16));
    $auth = new OauthAuthCodeRecord();
    $auth->code = $code;
    $auth->clientId = $clientId;
    $auth->userId = 1;
    $auth->redirectUri = 'http://localhost/cb';
    $auth->codeChallenge = $pkce['challenge'];
    $auth->codeChallengeMethod = 'S256';
    // 60 seconds in the past — well outside the 10-minute TTL.
    $auth->expiresAt = gmdate('Y-m-d H:i:s', time() - 60);
    $auth->consumed = false;
    $auth->save(false);

    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://localhost/cb',
        'code_verifier' => $pkce['verifier'],
    ]);

    $response->assertStatus(400);
    $payload = $response->json();
    expect($payload['error'])->toBe('invalid_grant');
    expect($payload['error_description'])->toContain('expired');
});

it('rejects a token exchange whose redirect_uri does not match the auth code', function () {
    $clientId = makeOauthClient(['clientId' => 'cai_token_redirect']);
    $pkce = pkcePair();

    $code = bin2hex(random_bytes(16));
    $auth = new OauthAuthCodeRecord();
    $auth->code = $code;
    $auth->clientId = $clientId;
    $auth->userId = 1;
    $auth->redirectUri = 'http://localhost/original-cb';
    $auth->codeChallenge = $pkce['challenge'];
    $auth->codeChallengeMethod = 'S256';
    $auth->expiresAt = gmdate('Y-m-d H:i:s', time() + 300);
    $auth->consumed = false;
    $auth->save(false);

    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://localhost/different-cb',
        'code_verifier' => $pkce['verifier'],
    ]);

    $response->assertStatus(400);
    $payload = $response->json();
    expect($payload['error'])->toBe('invalid_grant');
});

it('rejects a token exchange against an already-consumed authorization code', function () {
    $clientId = makeOauthClient(['clientId' => 'cai_token_replay']);
    $pkce = pkcePair();

    $code = bin2hex(random_bytes(16));
    $auth = new OauthAuthCodeRecord();
    $auth->code = $code;
    $auth->clientId = $clientId;
    $auth->userId = 1;
    $auth->redirectUri = 'http://localhost/cb';
    $auth->codeChallenge = $pkce['challenge'];
    $auth->codeChallengeMethod = 'S256';
    $auth->expiresAt = gmdate('Y-m-d H:i:s', time() + 300);
    // Already consumed — a previous successful exchange burned it.
    $auth->consumed = true;
    $auth->save(false);

    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://localhost/cb',
        'code_verifier' => $pkce['verifier'],
    ]);

    $response->assertStatus(400);
    $payload = $response->json();
    expect($payload['error'])->toBe('invalid_grant');
});

it('rotates the refresh token on the refresh_token grant', function () {
    $clientId = makeOauthClient(['clientId' => 'cai_refresh_rotate']);

    $original = new OauthTokenRecord();
    $original->accessToken = bin2hex(random_bytes(16));
    $original->refreshToken = bin2hex(random_bytes(16));
    $original->clientId = $clientId;
    $original->userId = 1;
    $original->scope = 'mcp';
    $original->accessExpiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
    $original->refreshExpiresAt = gmdate('Y-m-d H:i:s', time() + 86400);
    $original->revoked = false;
    $original->save(false);

    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $original->refreshToken,
    ]);

    $response->assertOk();
    $payload = $response->json();

    expect($payload['access_token'])->toBeString();
    expect($payload['refresh_token'])->toBeString();
    // Rotation: the old refresh token must NOT be reissued. If it were,
    // a leaked refresh token would remain valid even after the client
    // refreshed, defeating rotation as a defense.
    expect($payload['refresh_token'])->not->toBe($original->refreshToken);

    // The original row should now be revoked.
    $reloaded = OauthTokenRecord::findOne(['refreshToken' => $original->refreshToken]);
    expect((bool) $reloaded->revoked)->toBeTrue();
});

it('rejects a refresh_token grant after the original has been revoked', function () {
    $clientId = makeOauthClient(['clientId' => 'cai_refresh_revoked']);

    $token = new OauthTokenRecord();
    $token->accessToken = bin2hex(random_bytes(16));
    $token->refreshToken = bin2hex(random_bytes(16));
    $token->clientId = $clientId;
    $token->userId = 1;
    $token->scope = 'mcp';
    $token->accessExpiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
    $token->refreshExpiresAt = gmdate('Y-m-d H:i:s', time() + 86400);
    $token->revoked = true;
    $token->save(false);

    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $token->refreshToken,
    ]);

    $response->assertStatus(400);
    $payload = $response->json();
    expect($payload['error'])->toBe('invalid_grant');
});

it('rejects an unsupported grant_type', function () {
    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'password',
        'username' => 'admin',
        'password' => 'admin',
    ]);

    $response->assertStatus(400);
    $payload = $response->json();
    expect($payload['error'])->toBe('unsupported_grant_type');
});

it('rejects a confidential client without valid credentials on token exchange', function () {
    // Stand up a confidential client (auth method `client_secret_basic`) so
    // the token endpoint must verify the secret in addition to PKCE.
    $rawSecret = bin2hex(random_bytes(16));
    $clientId = makeOauthClient([
        'clientId' => 'cai_token_confidential',
        'tokenEndpointAuthMethod' => 'client_secret_basic',
        'clientSecretHash' => password_hash($rawSecret, PASSWORD_BCRYPT),
    ]);
    $pkce = pkcePair();

    $code = bin2hex(random_bytes(16));
    $auth = new OauthAuthCodeRecord();
    $auth->code = $code;
    $auth->clientId = $clientId;
    $auth->userId = 1;
    $auth->redirectUri = 'http://localhost/cb';
    $auth->codeChallenge = $pkce['challenge'];
    $auth->codeChallengeMethod = 'S256';
    $auth->expiresAt = gmdate('Y-m-d H:i:s', time() + 300);
    $auth->consumed = false;
    $auth->save(false);

    // No client_id / client_secret in the body and no Authorization header.
    $response = postOauthForm('craft-ai/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://localhost/cb',
        'code_verifier' => $pkce['verifier'],
    ]);

    $response->assertStatus(401);
    $payload = $response->json();
    expect($payload['error'])->toBe('invalid_client');
});

it('exposes the authorization-server metadata document', function () {
    $response = test()->get('?action=craft-ai/oauth/authorization-server-metadata');

    $response->assertOk();
    $payload = $response->json();

    expect($payload['issuer'])->toBeString();
    expect($payload['authorization_endpoint'])->toBeString();
    expect($payload['token_endpoint'])->toBeString();
    expect($payload['response_types_supported'])->toBe(['code']);
    expect($payload['grant_types_supported'])->toBe(['authorization_code', 'refresh_token']);
    expect($payload['code_challenge_methods_supported'])->toBe(['S256']);
});
