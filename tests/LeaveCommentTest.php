<?php

use Craft;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\tools\LeaveComment;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(LeaveComment::class);

    /** @var ToolContext $context */
    $context = Craft::$container->get(ToolContext::class);
    $context->begin('test-session-leave', 'tu-leave', ClientType::CP);
});

it('saves a comment on a specific field of a canonical entry', function () {
    $entry = Entry::factory()->section('posts')->title('My Post')->create();

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
        'fieldHandle' => 'title',
        'body' => 'The title buries the lede.',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data']['fieldHandle'])->toBe('title');
    expect($payload['data']['body'])->toBe('The title buries the lede.');
    expect($payload['data']['status'])->toBe('open');
    expect($payload['data']['isDraft'])->toBeFalse();

    $record = CommentRecord::findOne(['id' => $payload['data']['id']]);
    expect($record)->not->toBeNull();
    expect($record->sessionId)->toBe('test-session-leave');
    expect($record->elementId)->toBe((int) $entry->id);
});

it('saves a top-level comment when fieldHandle is omitted', function () {
    $entry = Entry::factory()->section('posts')->title('Post')->create();

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
        'body' => 'Overall the structure needs work.',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data']['fieldHandle'])->toBeNull();
});

it('rejects passing both entryId and draftId', function () {
    $entry = Entry::factory()->section('posts')->create();
    // Make a draft for variety; the binders will resolve both args.
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
        'draftId' => $draft->draftId,
        'body' => 'conflicting',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('exactly one');
});

it('errors when neither entryId nor draftId is provided', function () {
    $output = $this->registry->execute('leave_comment', [
        'body' => 'no target',
    ]);

    expect($output->isError)->toBeTrue();
});

it('errors when body is missing', function () {
    $entry = Entry::factory()->section('posts')->create();

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
    ]);

    expect($output->isError)->toBeTrue();
});

it('errors when entryId does not exist', function () {
    $output = $this->registry->execute('leave_comment', [
        'entryId' => 999999,
        'body' => 'orphan',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});

it('marks the row as a draft when draftId is provided', function () {
    $entry = Entry::factory()->section('posts')->title('Canonical')->create();
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    $output = $this->registry->execute('leave_comment', [
        'draftId' => $draft->draftId,
        'fieldHandle' => 'title',
        'body' => 'draft comment',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data']['isDraft'])->toBeTrue();
    expect($payload['data']['elementId'])->toBe((int) $draft->draftId);
});
