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
 * Save a real, query-able User via the craft-pest factory so resolveUser
 * can find it. The raw-SQL pattern used elsewhere in this suite skips the
 * elements_sites row that getElementById requires, so it isn't usable here.
 *
 * active/pending can be passed straight to the factory because User::afterSave
 * honors them on the initial-save branch. suspended/locked have to be flipped
 * with a follow-up UPDATE — afterSave raises an exception if they're flipped
 * via re-save, and User::init auto-unlocks users with locked=1 but no
 * lockoutDate, so locked fixtures also need a recent lockoutDate.
 */
function makeMcpUser(array $attributes = []): User
{
    $suffix = bin2hex(random_bytes(4));

    /** @var User $user */
    $user = \markhuot\craftpest\factories\User::factory()->create([
        'username' => $attributes['username'] ?? 'mcp-'.$suffix,
        'email' => $attributes['email'] ?? 'mcp-'.$suffix.'@example.com',
        'admin' => $attributes['admin'] ?? false,
        // active defaults true so the resolveUser STATUS_ACTIVE check passes
        // for the happy-path tests. Status-flag fixtures override this below.
        'active' => $attributes['active'] ?? true,
        'pending' => $attributes['pending'] ?? false,
    ]);

    // suspended/locked can't be passed to the initial save because
    // User::afterSave only honors them on the new-record branch when active is
    // already false. Push them through with a parameter-bound UPDATE so the
    // boolean → tinyint coercion lands the same way as a normal Craft write,
    // then invalidate Craft's per-element caches so a subsequent User query
    // reflects the freshly written flags.
    $postSaveOverrides = [];
    if (! empty($attributes['locked'])) {
        // User::init() auto-unlocks users whose cooldown window has elapsed
        // — i.e. anyone with locked=1 but no lockoutDate. Stamping the lockout
        // to "now" keeps the user inside the cooldown window so the auto-unlock
        // doesn't fire when we re-read them.
        $postSaveOverrides['locked'] = 1;
        $postSaveOverrides['lockoutDate'] = \craft\helpers\Db::prepareDateForDb(new \DateTime());
    }
    if (! empty($attributes['suspended'])) {
        $postSaveOverrides['suspended'] = 1;
    }
    if ($postSaveOverrides !== []) {
        \Craft::$app->getDb()->createCommand()
            ->update(
                \Craft::$app->getDb()->getSchema()->getRawTableName('{{%users}}'),
                $postSaveOverrides,
                ['id' => $user->id],
            )
            ->execute();
        \Craft::$app->getElements()->invalidateCachesForElement($user);
    }

    $reloaded = User::find()->id($user->id)->status(null)->one();
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
    Craft::$app->getUser()->setIdentity(null);
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
