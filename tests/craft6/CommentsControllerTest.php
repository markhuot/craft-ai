<?php

use CraftCms\Cms\Section\Enums\SectionType;

use Craft;
use craft\elements\User;
use CraftCms\Cms\Entry\Models\EntryType;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\Models\Field;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\Models\FieldLayout;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Str;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;

beforeEach(function () {
    seedSection('posts', 'Posts');

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    $this->loginCraftUser((int) $user->id);

    Craft::$container->setSingleton(LlmProvider::class, fn () => new class implements LlmProvider {
        public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
        {
            return new ProviderResponse('msg_test', [['type' => 'text', 'text' => 'ok']], 'end_turn');
        }
    });
});

function makeStoredComment(array $attrs = []): CommentRecord {
    $record = new CommentRecord();
    $record->sessionId = $attrs['sessionId'] ?? 'sess-controller';
    $record->elementId = $attrs['elementId'] ?? 1;
    $record->isDraft = $attrs['isDraft'] ?? false;
    $record->fieldHandle = $attrs['fieldHandle'] ?? null;
    $record->body = $attrs['body'] ?? 'comment body';
    $record->status = $attrs['status'] ?? CommentRecord::STATUS_OPEN;
    if (isset($attrs['authorMessageId'])) {
        $record->authorMessageId = $attrs['authorMessageId'];
    }
    if (isset($attrs['threadSessionId'])) {
        $record->threadSessionId = $attrs['threadSessionId'];
    }
    $record->save();
    return $record;
}

/**
 * Seed a parent session with a handful of messages so open-thread can
 * fork it. Returns the id of the assistant message that "left the
 * comment" — the natural anchor for the fork.
 */
function makeParentSession(string $sessionId): int {
    $session = new SessionRecord();
    $session->id = $sessionId;
    $session->active = false;
    $session->clientType = 'cp';
    $session->toolMode = 'full';
    $session->save();

    $user = new MessageRecord();
    $user->sessionId = $sessionId;
    $user->role = 'user';
    $user->content = json_encode([['type' => 'text', 'text' => 'review this entry']]);
    $user->save();

    $assistant = new MessageRecord();
    $assistant->sessionId = $sessionId;
    $assistant->role = 'assistant';
    $assistant->content = json_encode([['type' => 'text', 'text' => 'left a comment']]);
    $assistant->save();

    return (int) $assistant->id;
}

function seedBlockEntryType(string $name, string $handle, array $fields): EntryType
{
    $elements = array_map(static fn ($field) => [
        'uid' => (string) Str::uuid(),
        'type' => CustomField::class,
        'fieldUid' => $field->uid,
        'required' => false,
    ], $fields);

    $layout = FieldLayout::factory()->withContentTab($elements)->create();

    $entryType = EntryType::factory()->create([
        'name' => $name,
        'handle' => $handle,
        'fieldLayoutId' => $layout->id,
        'hasTitleField' => false,
    ]);

    EntryTypes::refreshEntryTypes();

    return $entryType;
}

function seedMatrixField(string $name, string $handle, array $entryTypes): Matrix
{
    $matrix = Field::factory()->create([
        'name' => $name,
        'handle' => $handle,
        'type' => Matrix::class,
        'settings' => [
            'entryTypes' => array_map(static fn (EntryType $et) => $et->uid, $entryTypes),
            'viewMode' => 'blocks',
        ],
    ]);

    Fields::refreshFields();

    return Fields::getFieldByHandle($handle);
}

it('returns the list of open comments scoped to an element', function () {
    $entry = seedEntry('posts', ['title' => 'Entry']);
    makeStoredComment(['elementId' => $entry->id, 'body' => 'open 1']);
    makeStoredComment(['elementId' => $entry->id, 'body' => 'open 2']);
    makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'resolved',
        'status' => CommentRecord::STATUS_RESOLVED,
    ]);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$entry->id.'&isDraft=0');

    $response->assertOk();
    $response->assertJsonCount(2, 'comments');
});

it('also returns comments on Matrix-nested entries when scoped to the parent', function () {
    // Build a section with a Matrix field containing a heading block type.
    $headingField = seedField('headingText', 'Heading Text', PlainText::class);
    $headingBlock = seedBlockEntryType('Heading', 'heading', [$headingField]);
    $matrix = seedMatrixField('Body', 'body', [$headingBlock]);
    seedSection('blog', 'Blog', SectionType::Channel, [$matrix]);

    $upsert = new ToolRegistry();
    $upsert->register(UpsertEntry::class);
    $created = decode($upsert->execute('upsert_entry', [
        'section' => 'blog',
        'title' => 'A post',
        'fields' => [
            'body' => [
                'new1' => ['type' => 'heading', 'fields' => ['headingText' => 'A heading']],
            ],
        ],
    ]));
    $parentId = (int) $created['data']['entry']['id'];
    $parent = \craft\elements\Entry::find()->id($parentId)->status(null)->one();
    $blockId = (int) $parent->body->all()[0]->id;

    makeStoredComment(['elementId' => $parentId, 'body' => 'top-level note', 'fieldHandle' => 'title']);
    makeStoredComment(['elementId' => $blockId, 'body' => 'nested note', 'fieldHandle' => 'headingText']);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$parentId.'&isDraft=0');

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    $bodies = array_map(fn ($c) => $c['body'], $body['comments']);
    expect($bodies)->toContain('top-level note');
    expect($bodies)->toContain('nested note');

    // Each row carries its own elementId + elementUid so the overlay
    // can pin the dot to the right block container.
    $nested = collect($body['comments'])->firstWhere('body', 'nested note');
    expect($nested['elementId'])->toBe($blockId);
    expect($nested['elementUid'])->toBeString();
});

it('marks a comment resolved by user and appends a system note to the session', function () {
    $entry = seedEntry('posts', ['title' => 'Entry']);
    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'fix the title',
        'fieldHandle' => 'title',
        'sessionId' => 'sess-resolve-1',
    ]);

    $response = test()->postJson('admin?action=craft-ai/comments/resolve', [
        'commentId' => $comment->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('comment.status', 'resolved');
    $response->assertJsonPath('comment.resolvedBy', 'user');

    $note = MessageRecord::find()
        ->where(['sessionId' => 'sess-resolve-1', 'role' => 'system'])
        ->one();
    expect($note)->not->toBeNull();
    expect($note->content)->toContain('resolved comment');
});

it('forks the originating session when opening a comment thread', function () {
    $entry = seedEntry('posts', ['title' => 'Entry']);
    $anchorId = makeParentSession('sess-open-1');
    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'reword this',
        'fieldHandle' => 'title',
        'sessionId' => 'sess-open-1',
        'authorMessageId' => $anchorId,
    ]);

    $response = test()->postJson('admin?action=craft-ai/comments/open-thread', [
        'commentId' => $comment->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('created', true);

    $body = json_decode((string) $response->getContent(), true);
    expect($body['threadSessionId'] ?? null)->toBeString();
    $forkId = $body['threadSessionId'];
    expect($forkId)->not->toBe('sess-open-1');

    $fork = SessionRecord::findOne(['id' => $forkId]);
    expect($fork)->not->toBeNull();
    expect($fork->parentSessionId)->toBe('sess-open-1');
    expect((int) $fork->originatingCommentId)->toBe((int) $comment->id);
    expect($fork->forkPivotMessageId)->not->toBeNull();

    // Two parent messages were copied + one seed system note. We don't
    // pin the exact count beyond "at least the seed exists" so the test
    // tolerates future tweaks to the seed prose.
    $forkMsgCount = (int) MessageRecord::find()
        ->where(['sessionId' => $forkId])
        ->count();
    expect($forkMsgCount)->toBeGreaterThanOrEqual(3);

    $systemMsg = MessageRecord::find()
        ->where(['sessionId' => $forkId, 'role' => 'system'])
        ->one();
    expect($systemMsg)->not->toBeNull();
    expect($systemMsg->content)->toContain('opened comment #'.$comment->id);

    // Comment row should now point back at the fork.
    $refreshed = CommentRecord::findOne(['id' => $comment->id]);
    expect($refreshed->threadSessionId)->toBe($forkId);
});

it('titles the forked thread session from the originating comment, not the parent title', function () {
    $entry = seedEntry('posts', ['title' => 'Entry']);
    $anchorId = makeParentSession('sess-fork-title-1');

    // Pin a known title on the parent so we can prove the fork does
    // NOT just inherit it.
    $parent = SessionRecord::findOne(['id' => 'sess-fork-title-1']);
    $parent->title = 'Original parent title';
    $parent->save();

    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'this headline buries the lede',
        'fieldHandle' => 'title',
        'sessionId' => 'sess-fork-title-1',
        'authorMessageId' => $anchorId,
    ]);

    $response = test()->postJson('admin?action=craft-ai/comments/open-thread', [
        'commentId' => $comment->id,
    ]);

    $response->assertOk();
    $body = json_decode((string) $response->getContent(), true);
    $fork = SessionRecord::findOne(['id' => $body['threadSessionId']]);

    expect($fork)->not->toBeNull();
    expect($fork->title)->not->toBe('Original parent title');
    expect($fork->title)->toContain('this headline buries the lede');
});

it('returns the existing thread session on subsequent open calls', function () {
    // The "first call forks, subsequent calls return the existing thread"
    // contract is split out from a single two-call test because the Craft
    // request is a process singleton whose parsed body params are cached on
    // first read and the harness reuses that one instance — a second
    // `postJson()` in the same test body would replay the first request. So
    // here we seed a comment whose thread fork ALREADY exists (the state a
    // second open-thread call would see) and assert a single call returns
    // the existing thread with `created => false`.
    $entry = seedEntry('posts', ['title' => 'Entry']);
    $anchorId = makeParentSession('sess-open-2');

    // Stand up the already-existing fork session the comment points at.
    $fork = new SessionRecord();
    $fork->id = 'sess-open-2-fork';
    $fork->parentSessionId = 'sess-open-2';
    $fork->active = false;
    $fork->clientType = 'cp';
    $fork->toolMode = 'full';
    $fork->save();

    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'reword',
        'sessionId' => 'sess-open-2',
        'authorMessageId' => $anchorId,
        'threadSessionId' => 'sess-open-2-fork',
    ]);

    $response = test()->postJson('admin?action=craft-ai/comments/open-thread', [
        'commentId' => $comment->id,
    ]);
    $body = json_decode((string) $response->getContent(), true);

    expect($body['created'])->toBeFalse();
    expect($body['threadSessionId'])->toBe('sess-open-2-fork');
});

it('creates a user-initiated comment with a referenceId and a fresh session', function () {
    $entry = seedEntry('posts', ['title' => 'Entry']);

    $response = test()->postJson('admin?action=craft-ai/comments/create', [
        'elementId' => $entry->id,
        'isDraft' => 0,
        'fieldHandle' => 'bodyContent',
        'body' => 'Tighten this paragraph.',
        'referenceId' => 'ref-abc-123',
    ]);

    expect($response->getStatusCode())->toBe(200);
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['ok'])->toBeTrue();
    expect($payload['comment']['referenceId'])->toBe('ref-abc-123');
    expect($payload['comment']['fieldHandle'])->toBe('bodyContent');
    expect($payload['comment']['body'])->toBe('Tighten this paragraph.');
    expect($payload['comment']['status'])->toBe(CommentRecord::STATUS_OPEN);

    // The endpoint should have created a brand-new SessionRecord that
    // owns the eventual discussion thread. The user shouldn't have to
    // wait until they open the popover to see the session exist.
    $sessionId = $payload['comment']['sessionId'];
    $session = SessionRecord::findOne(['id' => $sessionId]);
    expect($session)->not->toBeNull();
    expect($session->clientType)->toBe('cp');

    // Server should also drop a system message describing the comment
    // so the agent has grounding when the editor opens the thread.
    $systemNote = MessageRecord::find()
        ->where(['sessionId' => $sessionId, 'role' => 'system'])
        ->one();
    expect($systemNote)->not->toBeNull();
    expect((string) $systemNote->content)->toContain('Tighten this paragraph.');
});

it('generates a referenceId server-side when the client omits one', function () {
    $entry = seedEntry('posts', ['title' => 'Entry']);

    $response = test()->postJson('admin?action=craft-ai/comments/create', [
        'elementId' => $entry->id,
        'fieldHandle' => 'bodyContent',
        'body' => 'no client ref',
    ]);

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['ok'])->toBeTrue();
    expect($payload['comment']['referenceId'])->toBeString();
    expect(strlen($payload['comment']['referenceId']))->toBeGreaterThan(0);
});

it('rejects an empty body on create', function () {
    $this->withoutExceptionHandling();
    $entry = seedEntry('posts', ['title' => 'Entry']);

    $threw = false;
    try {
        test()->postJson('admin?action=craft-ai/comments/create', [
            'elementId' => $entry->id,
            'fieldHandle' => 'bodyContent',
            'body' => '   ',
        ]);
    } catch (\yii\web\BadRequestHttpException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('404s on create when the elementId points at nothing', function () {
    test()->postJson('admin?action=craft-ai/comments/create', [
        'elementId' => 999999,
        'fieldHandle' => 'bodyContent',
        'body' => 'hello',
    ])->assertNotFound();
});

it('does not surface a draft comment when the user views the canonical', function () {
    // Strict scoping: a comment authored on a draft is in-flight feedback
    // about that working copy and must NOT leak onto the live entry. The
    // canonical view returns an empty set when the only comment lives on
    // a draft.
    $entry = seedEntry('posts', ['title' => 'Test Entry']);
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    makeStoredComment([
        'elementId' => (int) $draft->draftId,
        'isDraft' => true,
        'fieldHandle' => 'storyContent',
        'body' => 'comment authored on the draft',
    ]);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$entry->id.'&isDraft=0');

    $response->assertOk();
    $response->assertJsonCount(0, 'comments');
});

it('does not surface a canonical comment when the user views a draft', function () {
    // The mirror case: a draft is its own working copy and only shows the
    // feedback anchored to that draft.
    $entry = seedEntry('posts', ['title' => 'Test Entry']);
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    makeStoredComment([
        'elementId' => (int) $entry->id,
        'isDraft' => false,
        'fieldHandle' => 'storyContent',
        'body' => 'comment authored on the canonical',
    ]);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$draft->draftId.'&isDraft=1');

    $response->assertOk();
    $response->assertJsonCount(0, 'comments');
});

// The core of the reported bug: entry 334 showed its drafts' comments.
// With strict scoping each surface returns exactly its own comment.
//
// Split across two tests (canonical-side and draft-side) because Craft's
// request is a process singleton whose parsed query params are cached on
// first read; the test harness reuses that one instance, so a second
// `test()->get()` in the same test body would still see the first request's
// elementId/isDraft. Each test below issues a single request after seeding
// BOTH comments, so the "they coexist, each side sees only its own"
// guarantee is still fully exercised.
function seedBothSidesComments(): array {
    $entry = seedEntry('posts', ['title' => 'Test Entry']);
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    makeStoredComment([
        'elementId' => (int) $entry->id,
        'isDraft' => false,
        'fieldHandle' => 'storyContent',
        'body' => 'on canonical',
    ]);
    makeStoredComment([
        'elementId' => (int) $draft->draftId,
        'isDraft' => true,
        'fieldHandle' => 'storyContent',
        'body' => 'on draft',
    ]);

    return [$entry, $draft];
}

it('surfaces only the canonical comment when viewing the canonical, with a draft comment also present', function () {
    [$entry, $draft] = seedBothSidesComments();

    $canonical = test()->get('admin?action=craft-ai/comments&elementId='.$entry->id.'&isDraft=0');
    $canonical->assertOk();
    $canonical->assertJsonCount(1, 'comments');
    expect(json_decode((string) $canonical->getContent(), true)['comments'][0]['body'])->toBe('on canonical');
});

it('surfaces only the draft comment when viewing the draft, with a canonical comment also present', function () {
    [$entry, $draft] = seedBothSidesComments();

    $draftView = test()->get('admin?action=craft-ai/comments&elementId='.$draft->draftId.'&isDraft=1');
    $draftView->assertOk();
    $draftView->assertJsonCount(1, 'comments');
    expect(json_decode((string) $draftView->getContent(), true)['comments'][0]['body'])->toBe('on draft');
});

it('serializes existing comments with referenceId in the index payload', function () {
    $entry = seedEntry('posts', ['title' => 'Entry']);
    makeStoredComment([
        'elementId' => $entry->id,
        'fieldHandle' => 'bodyContent',
        'body' => 'span comment',
    ])->updateAttributes(['referenceId' => 'ref-xyz-789']);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$entry->id.'&isDraft=0');
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['comments'])->toHaveCount(1);
    expect($payload['comments'][0]['referenceId'])->toBe('ref-xyz-789');
});

it('404s on an unknown commentId for resolve', function () {
    // With Craft's exception handler in place, the controller's
    // NotFoundHttpException renders as a 404 response.
    test()->postJson('admin?action=craft-ai/comments/resolve', [
        'commentId' => 999999,
    ])->assertNotFound();
});

it('refuses to resolve a comment that belongs to another user\'s session', function () {
    // Stand up a foreign user who owns the parent session of a comment.
    // The currently-logged-in user (admin, id=1) should not be able to
    // resolve the comment even though they're authenticated — the
    // ownership check on the originating session has to gate this.
    $otherId = createOtherUser('comment-resolve-other');

    $entry = seedEntry('posts', ['title' => 'Entry']);

    $theirSession = new SessionRecord();
    $theirSession->id = 'sess-theirs-resolve';
    $theirSession->userId = $otherId;
    $theirSession->save();

    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'sessionId' => 'sess-theirs-resolve',
        'body' => 'do not touch',
    ]);

    test()->postJson('admin?action=craft-ai/comments/resolve', [
        'commentId' => $comment->id,
    ])->assertNotFound();

    // Comment must remain open — the denial is real, not just a swallowed
    // 404 with the write still happening.
    $reloaded = CommentRecord::findOne(['id' => $comment->id]);
    expect($reloaded->status)->toBe(CommentRecord::STATUS_OPEN);
});

it('refuses to open a comment thread whose parent session belongs to another user', function () {
    // P0 fix: actionOpenThread used to fork sessions without verifying the
    // current user owned the parent. A malicious caller could enumerate
    // commentIds, fork foreign sessions, and either read forked history
    // or pollute the original via the seed system note.
    $otherId = createOtherUser('comment-open-other');

    $entry = seedEntry('posts', ['title' => 'Entry']);

    $theirSession = new SessionRecord();
    $theirSession->id = 'sess-theirs-open';
    $theirSession->userId = $otherId;
    $theirSession->save();

    // Seed the parent session so a fork *could* be created if the check
    // weren't in place — this proves the test is exercising the auth
    // path, not silently failing on "nothing to fork."
    $msg = new MessageRecord();
    $msg->sessionId = 'sess-theirs-open';
    $msg->role = 'assistant';
    $msg->content = json_encode([['type' => 'text', 'text' => 'left a note']]);
    $msg->save();

    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'sessionId' => 'sess-theirs-open',
        'body' => 'foreign comment',
        'authorMessageId' => (int) $msg->id,
    ]);

    test()->postJson('admin?action=craft-ai/comments/open-thread', [
        'commentId' => $comment->id,
    ])->assertNotFound();

    // No fork should have been created. If one was, the attacker would
    // now have a session id pointing into foreign conversation history.
    $reloaded = CommentRecord::findOne(['id' => $comment->id]);
    expect($reloaded->threadSessionId)->toBeNull();

    $forkCount = (int) SessionRecord::find()
        ->where(['parentSessionId' => 'sess-theirs-open'])
        ->count();
    expect($forkCount)->toBe(0);
});
