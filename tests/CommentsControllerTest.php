<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\records\MessageRecord;
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
    $record->save();
    return $record;
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

it('queues an agent run when replying to a comment', function () {
    $entry = Entry::factory()->section('posts')->title('Entry')->create();
    $comment = makeStoredComment([
        'elementId' => $entry->id,
        'body' => 'reword this',
        'fieldHandle' => 'title',
        'sessionId' => 'sess-reply-1',
    ]);

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/reply',
            'commentId' => $comment->id,
            'message' => 'I disagree, what about this angle?',
        ])
        ->send();

    $response->assertOk();
    $response->assertJsonPath('queued', true);
    $response->assertJsonPath('sessionId', 'sess-reply-1');

    $userMsg = MessageRecord::find()
        ->where(['sessionId' => 'sess-reply-1', 'role' => 'user'])
        ->one();
    expect($userMsg)->not->toBeNull();
    expect($userMsg->content)->toContain('Re: comment #'.$comment->id);
    expect($userMsg->content)->toContain('I disagree');

    $systemMsg = MessageRecord::find()
        ->where(['sessionId' => 'sess-reply-1', 'role' => 'system'])
        ->one();
    expect($systemMsg)->not->toBeNull();
    expect($systemMsg->content)->toContain('User replied to comment #'.$comment->id);
});

it('rejects an empty reply', function () {
    $comment = makeStoredComment();

    $response = test()->http('post', 'admin')
        ->withCsrfToken()
        ->setBody([
            'action' => 'craft-ai/comments/reply',
            'commentId' => $comment->id,
            'message' => '   ',
        ])
        ->send();

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
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
