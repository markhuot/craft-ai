<?php

use Craft;
use craft\fields\PlainText;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\tools\LeaveComment;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\EntryType;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\MatrixField as MatrixFieldFactory;
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

it('files a comment against the block\'s own entry id when targeting a Matrix block field', function () {
    // Set up a Matrix field with one block type that has an inner heading
    // field. Then create an entry containing one block and verify the
    // model can leave a comment scoped to that block's elementId — the
    // dot needs to land inside the block, not on the outer Matrix field.
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
    // beforeEach builds a plain `posts` section with no matrix — add a
    // separate section that has the matrix attached so this test stays
    // self-contained.
    Section::factory()->name('Blog')->handle('blog')->fields($matrix)->create();

    $upsert = new ToolRegistry();
    $upsert->register(UpsertEntry::class);

    $created = decode($upsert->execute('upsert_entry', [
        'section' => 'blog',
        'title' => 'A post',
        'fields' => [
            'body' => [
                'new1' => ['type' => 'heading', 'fields' => ['headingText' => 'What Are Alt Tags?']],
            ],
        ],
    ]));
    $entryId = $created['data']['entry']['id'];
    $entry = \craft\elements\Entry::find()->id($entryId)->status(null)->one();
    $block = $entry->body->all()[0];
    $blockId = (int) $block->id;

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $blockId,
        'fieldHandle' => 'headingText',
        'body' => 'Too formal — make it playful.',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data']['elementId'])->toBe($blockId);
    expect($payload['data']['fieldHandle'])->toBe('headingText');

    $record = CommentRecord::findOne(['id' => $payload['data']['id']]);
    expect($record)->not->toBeNull();
    expect((int) $record->elementId)->toBe($blockId);
    expect($record->fieldHandle)->toBe('headingText');
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
