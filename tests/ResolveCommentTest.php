<?php

use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\tools\ResolveComment;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(ResolveComment::class);
});

function makeComment(array $attrs = []): CommentRecord {
    $record = new CommentRecord();
    $record->sessionId = $attrs['sessionId'] ?? 'sess-resolve';
    $record->elementId = $attrs['elementId'] ?? 1;
    $record->isDraft = $attrs['isDraft'] ?? false;
    $record->fieldHandle = $attrs['fieldHandle'] ?? null;
    $record->body = $attrs['body'] ?? 'comment body';
    $record->status = $attrs['status'] ?? CommentRecord::STATUS_OPEN;
    $record->save();
    return $record;
}

it('marks an open comment as resolved by the agent', function () {
    $comment = makeComment(['body' => 'needs fixing']);

    $output = $this->registry->execute('resolve_comment', [
        'commentId' => $comment->id,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $reloaded = CommentRecord::findOne(['id' => $comment->id]);
    expect($reloaded->status)->toBe(CommentRecord::STATUS_RESOLVED);
    expect($reloaded->resolvedBy)->toBe(CommentRecord::RESOLVED_BY_AGENT);
    expect($reloaded->resolvedAt)->not->toBeNull();
});

it('no-ops on an already-resolved comment', function () {
    $comment = makeComment([
        'status' => CommentRecord::STATUS_RESOLVED,
        'body' => 'previously resolved',
    ]);
    $comment->resolvedBy = CommentRecord::RESOLVED_BY_USER;
    $comment->resolvedAt = '2026-05-15 10:00:00';
    $comment->save();

    $output = $this->registry->execute('resolve_comment', [
        'commentId' => $comment->id,
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['_notes'])->toContain('already resolved');

    $reloaded = CommentRecord::findOne(['id' => $comment->id]);
    // resolvedBy should still be 'user' since we didn't overwrite.
    expect($reloaded->resolvedBy)->toBe(CommentRecord::RESOLVED_BY_USER);
});

it('errors when commentId does not exist', function () {
    $output = $this->registry->execute('resolve_comment', [
        'commentId' => 999999,
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});
