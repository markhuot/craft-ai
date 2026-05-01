<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\MessageRecord;

function loginTestUser(): void {
    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'test';
    $user->email = 'test@example.com';
    Craft::$app->getUser()->setIdentity($user);
}

beforeEach(function () {
    loginTestUser();

    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_test', [['type' => 'text', 'text' => 'ok']], 'end_turn');
        }
    });
});

it('renders the sessions index with grouped session rows', function () {
    $a = new MessageRecord();
    $a->sessionId = 'aaaa-1';
    $a->role = 'user';
    $a->content = json_encode([['type' => 'text', 'text' => 'hi']]);
    $a->save();

    $b = new MessageRecord();
    $b->sessionId = 'aaaa-1';
    $b->role = 'assistant';
    $b->content = json_encode([['type' => 'text', 'text' => 'hello']]);
    $b->save();

    $c = new MessageRecord();
    $c->sessionId = 'bbbb-2';
    $c->role = 'user';
    $c->content = json_encode([['type' => 'text', 'text' => 'yo']]);
    $c->save();

    $response = $this->get('admin/ai/sessions');

    $response->assertOk();
    $response->assertSee('aaaa-1');
    $response->assertSee('bbbb-2');
});

it('mints a new session id and redirects to its CP page', function () {
    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody(['action' => 'craft-ai/sessions/new'])
        ->send();

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toMatch('#/ai/session/[A-Za-z0-9\-]{36}$#');
});

it('renders the chat view with prior messages for the requested session', function () {
    $record = new MessageRecord();
    $record->sessionId = 'session-view-1';
    $record->role = 'user';
    $record->content = json_encode([['type' => 'text', 'text' => 'hello world']]);
    $record->save();

    $response = $this->get('admin/ai/session/session-view-1');

    $response->assertOk();
    $response->assertSee('session-view-1');
    $response->assertSee('hello world');
});

it('registers the chat asset bundle so chat.css and chat.js are loaded on the session page', function () {
    // Yii's View instance is reused across requests inside a single test
    // process, and Craft de-dupes asset bundles whose handle is already in
    // `Craft.registeredAssetBundles`. Reset that state so this test exercises
    // a fresh "first load" of the page.
    $view = Craft::$app->getView();
    $view->assetBundles = [];
    $view->registeredAssetBundles = [];

    $response = $this->get('admin/ai/session/asset-check');

    $response->assertOk();
    $body = (string) $response->content;

    // The compiled JS module is loaded as <script type="module" src="…/chat.js">.
    expect($body)->toMatch('#<script[^>]+type="module"[^>]+src="[^"]+/chat\.js[^"]*"#');

    // The compiled CSS is loaded as a stylesheet link. Yii's tag helper emits
    // attributes in href-then-rel order, so don't pin the attribute order.
    expect(preg_match('#<link\b[^>]*\bhref="([^"]+/chat\.css[^"]*)"[^>]*>#', $body, $m))
        ->toBe(1, 'No <link …chat.css…> tag was rendered on the page');
    expect($m[0])->toContain('rel="stylesheet"');

    // The bootstrap JSON island is rendered for the JS app to hydrate from.
    expect($body)->toContain('data-craftai-chat-root');
    expect($body)->toContain('data-craftai-bootstrap');

    // Verify the URL Craft published actually serves the built CSS file.
    $cssUrl = $m[1];
    $relative = parse_url($cssUrl, PHP_URL_PATH) ?: $cssUrl;
    $publishedPath = Craft::getAlias('@webroot').$relative;
    expect(file_exists($publishedPath))
        ->toBeTrue("Published chat.css not found on disk at {$publishedPath}");
    expect((string) file_get_contents($publishedPath))->toContain('--ai-color-craftai-border');
});

it('compiles chat.css with the Tailwind utilities the chat UI depends on', function () {
    $distCss = dirname(__DIR__).'/src/web/assets/chat/dist/chat.css';
    expect(file_exists($distCss))
        ->toBeTrue('Built chat.css is missing — run `bun run build` and commit the output.');

    $css = (string) file_get_contents($distCss);

    // Sanity check: the Tailwind v4 banner is present.
    expect($css)->toContain('tailwindcss');

    // Utility classes that the chat components rely on for layout/typography.
    // We use Tailwind v4's `prefix(ai)` modifier so every utility is namespaced
    // (e.g. `.ai\:flex`); the bare `.flex` selector must NOT appear or it
    // would mean the prefix regressed and we'd be polluting Craft's CP.
    foreach (['.ai\:justify-end', '.ai\:justify-start', '.ai\:rounded-lg', '.ai\:text-sm', '.ai\:flex'] as $needle) {
        expect(str_contains($css, $needle))
            ->toBeTrue("chat.css is missing expected utility: {$needle}");
    }
    expect(preg_match('/(?<![\\\\:])\.flex\{/', $css))
        ->toBe(0, 'Found an unprefixed `.flex` selector — the `ai:` prefix has regressed');

    // Custom theme tokens declared in resources/chat/styles.css must survive
    // the build. Tailwind v4's `prefix(ai)` mode renames CSS variables too,
    // so `--color-craftai-border` is exposed as `--ai-color-craftai-border`.
    expect($css)->toContain('--ai-color-craftai-border');

    // Craft CP ships its own preflight unlayered. To keep our utilities in
    // the same cascade tier (so normal specificity wins) we strip the
    // `@layer utilities` wrap during the build. If this regresses, every
    // `* { … }` reset Craft emits will silently override our utilities.
    expect($css)->not->toContain('@layer utilities{');
    expect($css)->not->toContain('!important', 'Utilities should win on specificity, not !important');
});

function postSend(array $body) {
    return test()->http('post', 'admin')
        ->withCsrfToken()
        ->addHeader('Accept', 'application/json')
        ->setBody(['action' => 'craft-ai/sessions/send', ...$body])
        ->send();
}

it('queues an AgentJob when the composer sends a message', function () {
    $response = postSend(['sessionId' => 'session-send-1', 'message' => 'do the thing']);

    $response->assertOk();
    $response->assertJsonPath('queued', true);
});

it('does not queue a job for an empty message', function () {
    $response = postSend(['sessionId' => 'session-send-2', 'message' => '   ']);

    $response->assertOk();
    $response->assertJsonPath('queued', false);
});
