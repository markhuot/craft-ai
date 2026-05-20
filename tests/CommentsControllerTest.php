<?php

use Craft;
use craft\elements\User;
use craft\fields\PlainText;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\EntryType;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\MatrixField as MatrixFieldFactory;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $user = new User();
    $user->id = 1;
    $user->admin = true;
    $user->username = 'admin';
    $user->email = 'admin@example.com';
    Craft::$app->getUser()->setIdentity($user);

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

it('returns the list of open comments scoped to an element', function () {
    $entry = Entry::factory()->section('posts')->title('Entry')->create();
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
    $headingField = Field::factory()->name('Heading Text')->handle('headingText')->type(PlainText::class);
    $headingBlock = EntryType::factory()
        ->name('Heading')
        ->handle('heading')
        ->hasTitleField(false)
        ->fields($headingField);
    $matrix = MatrixFieldFactory::factory()
        ->name('Body')
        ->handle('body')
        ->entryTypes($headingBlock)
        ->create();
    Section::factory()->name('Blog')->handle('blog')->fields($matrix)->create();

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
    $body = json_decode((string) $response->content, true);
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
    $entry = Entry::factory()->section('posts')->title('Entry')->create();
    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'fix the title',
        'fieldHandle' => 'title',
        'sessionId' => 'sess-resolve-1',
    ]);

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/resolve',
            'commentId' => $comment->id,
        ])
        ->send();

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
    $entry = Entry::factory()->section('posts')->title('Entry')->create();
    $anchorId = makeParentSession('sess-open-1');
    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'reword this',
        'fieldHandle' => 'title',
        'sessionId' => 'sess-open-1',
        'authorMessageId' => $anchorId,
    ]);

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/open-thread',
            'commentId' => $comment->id,
        ])
        ->send();

    $response->assertOk();
    $response->assertJsonPath('created', true);

    $body = json_decode((string) $response->content, true);
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
    $entry = Entry::factory()->section('posts')->title('Entry')->create();
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

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/open-thread',
            'commentId' => $comment->id,
        ])
        ->send();

    $response->assertOk();
    $body = json_decode((string) $response->content, true);
    $fork = SessionRecord::findOne(['id' => $body['threadSessionId']]);

    expect($fork)->not->toBeNull();
    expect($fork->title)->not->toBe('Original parent title');
    expect($fork->title)->toContain('this headline buries the lede');
});

it('returns the existing thread session on subsequent open calls', function () {
    $entry = Entry::factory()->section('posts')->title('Entry')->create();
    $anchorId = makeParentSession('sess-open-2');
    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'reword',
        'sessionId' => 'sess-open-2',
        'authorMessageId' => $anchorId,
    ]);

    $first = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/open-thread',
            'commentId' => $comment->id,
        ])
        ->send();
    $firstBody = json_decode((string) $first->content, true);
    $forkId = $firstBody['threadSessionId'];

    $second = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/open-thread',
            'commentId' => $comment->id,
        ])
        ->send();
    $secondBody = json_decode((string) $second->content, true);

    expect($secondBody['created'])->toBeFalse();
    expect($secondBody['threadSessionId'])->toBe($forkId);
});

it('creates a user-initiated comment with a referenceId and a fresh session', function () {
    $entry = Entry::factory()->section('posts')->title('Entry')->create();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/create',
            'elementId' => $entry->id,
            'isDraft' => 0,
            'fieldHandle' => 'bodyContent',
            'body' => 'Tighten this paragraph.',
            'referenceId' => 'ref-abc-123',
        ])
        ->send();

    expect($response->getStatusCode())->toBe(200);
    $payload = json_decode((string) $response->content, true);

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
    $entry = Entry::factory()->section('posts')->title('Entry')->create();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/create',
            'elementId' => $entry->id,
            'fieldHandle' => 'bodyContent',
            'body' => 'no client ref',
        ])
        ->send();

    $payload = json_decode((string) $response->content, true);
    expect($payload['ok'])->toBeTrue();
    expect($payload['comment']['referenceId'])->toBeString();
    expect(strlen($payload['comment']['referenceId']))->toBeGreaterThan(0);
});

it('rejects an empty body on create', function () {
    $entry = Entry::factory()->section('posts')->title('Entry')->create();

    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/create',
            'elementId' => $entry->id,
            'fieldHandle' => 'bodyContent',
            'body' => '   ',
        ])
        ->send();
})->throws(\yii\web\BadRequestHttpException::class);

it('404s on create when the elementId points at nothing', function () {
    test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/create',
            'elementId' => 999999,
            'fieldHandle' => 'bodyContent',
            'body' => 'hello',
        ])
        ->send();
})->throws(\yii\web\NotFoundHttpException::class);

it('surfaces a comment left on a draft when the user views the canonical', function () {
    // Repro of the "agent commented on a draft → editor reloads the
    // canonical view → no popover" bug. CommentScope::pairsFor needs
    // to bridge draft<->canonical in both directions so a comment
    // sticks to the entry regardless of which surface the editor is
    // looking at.
    $entry = Entry::factory()->section('posts')->title('Test Entry')->create();
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    makeStoredComment([
        'elementId' => (int) $draft->draftId,
        'isDraft' => true,
        'fieldHandle' => 'storyContent',
        'body' => 'comment authored on the draft',
    ]);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$entry->id.'&isDraft=0');

    $response->assertOk();
    $response->assertJsonCount(1, 'comments');
    $payload = json_decode((string) $response->content, true);
    expect($payload['comments'][0]['body'])->toBe('comment authored on the draft');
});

it('surfaces a comment left on the canonical when the user views a draft', function () {
    $entry = Entry::factory()->section('posts')->title('Test Entry')->create();
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    makeStoredComment([
        'elementId' => (int) $entry->id,
        'isDraft' => false,
        'fieldHandle' => 'storyContent',
        'body' => 'comment authored on the canonical',
    ]);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$draft->draftId.'&isDraft=1');

    $response->assertOk();
    $response->assertJsonCount(1, 'comments');
    $payload = json_decode((string) $response->content, true);
    expect($payload['comments'][0]['body'])->toBe('comment authored on the canonical');
});

it('serializes existing comments with referenceId in the index payload', function () {
    $entry = Entry::factory()->section('posts')->title('Entry')->create();
    makeStoredComment([
        'elementId' => $entry->id,
        'fieldHandle' => 'bodyContent',
        'body' => 'span comment',
    ])->updateAttributes(['referenceId' => 'ref-xyz-789']);

    $response = test()->get('admin?action=craft-ai/comments&elementId='.$entry->id.'&isDraft=0');
    $payload = json_decode((string) $response->content, true);

    expect($payload['comments'])->toHaveCount(1);
    expect($payload['comments'][0]['referenceId'])->toBe('ref-xyz-789');
});

it('404s on an unknown commentId for resolve', function () {
    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/resolve',
            'commentId' => 999999,
        ])
        ->send();

    expect($response->getStatusCode())->toBe(404);
});
