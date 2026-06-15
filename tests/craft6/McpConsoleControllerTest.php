<?php

use craft\elements\User;
use markhuot\craftai\console\controllers\McpController;
use yii\console\ExitCode;

beforeEach(function () {
    // Solo edition caps the user table at 1 user, which blocks the fixtures
    // below from creating distinct test users. The auth logic itself is
    // edition-agnostic, so just lift the cap for these tests.
    \Craft::$app->setEdition(\craft\enums\CmsEdition::Pro);

    $this->controller = new class('mcp', \Craft::$app) extends McpController {
        public function publicResolveUser(string $identifier): ?User
        {
            return $this->resolveUser($identifier);
        }
    };
});

/**
 * Save a real, query-able User via the native Craft 6 factory so resolveUser
 * can find it. The raw-SQL pattern used elsewhere in this suite skips the
 * elements_sites row that getElementById requires, so it isn't usable here.
 *
 * createElement() persists the full element (+ elements_sites) and returns the
 * User element. active/pending land cleanly through the factory. suspended /
 * locked don't survive the factory's element-save round trip reliably (the
 * factory's locked()/suspended() states set them on the Eloquent model but the
 * element-save path drops the lockoutDate/flags), so they're flipped with a
 * follow-up parameter-bound UPDATE — the same boolean → tinyint coercion a
 * normal Craft write performs. User::init() auto-unlocks any locked user whose
 * cooldown window has elapsed, so locked fixtures also stamp a recent
 * lockoutDate to stay inside the (default 300s) cooldown window. Caches are
 * invalidated so a subsequent User query reflects the freshly written flags.
 */
function makeMcpUser(array $attributes = []): User
{
    $suffix = bin2hex(random_bytes(4));

    $element = \CraftCms\Cms\User\Models\User::factory()->createElement([
        'username' => $attributes['username'] ?? 'mcp-'.$suffix,
        'email' => $attributes['email'] ?? 'mcp-'.$suffix.'@example.com',
        'admin' => $attributes['admin'] ?? false,
        // active defaults true so the resolveUser STATUS_ACTIVE check passes
        // for the happy-path tests. Status-flag fixtures override this below.
        'active' => $attributes['active'] ?? true,
        'pending' => $attributes['pending'] ?? false,
    ]);

    $postSaveOverrides = [];
    if (! empty($attributes['locked'])) {
        $postSaveOverrides['locked'] = 1;
        $postSaveOverrides['lockoutDate'] = \craft\helpers\Db::prepareDateForDb(new \DateTime());
        $postSaveOverrides['invalidLoginCount'] = 2;
    }
    if (! empty($attributes['suspended'])) {
        $postSaveOverrides['suspended'] = 1;
    }
    if ($postSaveOverrides !== []) {
        \Craft::$app->getDb()->createCommand()
            ->update(
                \Craft::$app->getDb()->getSchema()->getRawTableName('{{%users}}'),
                $postSaveOverrides,
                ['id' => $element->id],
            )
            ->execute();
        \Craft::$app->getElements()->invalidateCachesForElement($element);
    }

    $reloaded = User::find()->id($element->id)->status(null)->one();
    expect($reloaded)->toBeInstanceOf(User::class);

    return $reloaded;
}

it('exposes --user as an option for the serve action', function () {
    expect($this->controller->options('serve'))->toContain('user');
});

it('returns USAGE when --user is omitted', function () {
    $this->controller->user = null;

    expect($this->controller->actionServe())->toBe(ExitCode::USAGE);
});

it('returns USAGE when --user is blank whitespace', function () {
    $this->controller->user = '   ';

    expect($this->controller->actionServe())->toBe(ExitCode::USAGE);
});

it('returns DATAERR when --user does not resolve to a Craft user', function () {
    $this->controller->user = 'no-such-user-'.bin2hex(random_bytes(3));

    expect($this->controller->actionServe())->toBe(ExitCode::DATAERR);
});

it('does not set an identity when --user fails to resolve', function () {
    Craft::$app->getUser()->logout(false);
    $this->controller->user = '0';

    $this->controller->actionServe();

    expect(Craft::$app->getUser()->getIdentity())->toBeNull();
});

it('resolves a user by numeric ID', function () {
    $user = makeMcpUser();

    $resolved = $this->controller->publicResolveUser((string) $user->id);

    expect($resolved)->toBeInstanceOf(User::class);
    expect($resolved->id)->toBe($user->id);
});

it('resolves a user by username', function () {
    $user = makeMcpUser(['username' => 'mcp-by-username', 'email' => 'mcp-by-username@example.com']);

    $resolved = $this->controller->publicResolveUser('mcp-by-username');

    expect($resolved)->toBeInstanceOf(User::class);
    expect($resolved->id)->toBe($user->id);
});

it('resolves a user by email', function () {
    $user = makeMcpUser(['username' => 'mcp-by-email', 'email' => 'mcp-by-email@example.com']);

    $resolved = $this->controller->publicResolveUser('mcp-by-email@example.com');

    expect($resolved)->toBeInstanceOf(User::class);
    expect($resolved->id)->toBe($user->id);
});

it('returns null when no user matches the identifier', function () {
    expect($this->controller->publicResolveUser('definitely-not-a-real-user'))->toBeNull();
});

it('refuses to resolve a suspended user so they cannot bypass their lockout', function () {
    makeMcpUser(['username' => 'mcp-suspended', 'email' => 'mcp-suspended@example.com', 'suspended' => true]);

    expect($this->controller->publicResolveUser('mcp-suspended'))->toBeNull();
});

it('refuses to resolve a locked user', function () {
    makeMcpUser(['username' => 'mcp-locked', 'email' => 'mcp-locked@example.com', 'locked' => true]);

    expect($this->controller->publicResolveUser('mcp-locked'))->toBeNull();
});

it('refuses to resolve a pending user', function () {
    makeMcpUser(['username' => 'mcp-pending', 'email' => 'mcp-pending@example.com', 'active' => false, 'pending' => true]);

    expect($this->controller->publicResolveUser('mcp-pending'))->toBeNull();
});
