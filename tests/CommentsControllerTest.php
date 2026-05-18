<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftpest\factories\Entry;
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
