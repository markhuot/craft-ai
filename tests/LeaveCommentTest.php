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

require_once __DIR__.'/stubs/CkeditorFieldStub.php';

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

    $this->registry = new ToolRegistry();
    $this->registry->register(LeaveComment::class);

    /** @var ToolContext $context */
    $context = Craft::$container->get(ToolContext::class);
    $context->begin('test-session-leave', 'tu-leave', ClientType::CP);
});

it('teaches the agent the span-comment workflow in its description', function () {
    // The agent reads this description before deciding whether to
    // pin a comment to a specific span vs. dot the whole field. The
    // captured "first paragraph" session showed it defaulting to a
    // field-level comment because the doc was passively worded —
    // the description now needs to be explicit that the agent itself
    // generates the referenceId and wraps the span via upsert_entry
    // (not "wait for the user to highlight text and use the plugin").
    $descriptor = $this->registry->describe('leave_comment');

    expect($descriptor->description)->toContain('referenceId');
    // Must reference the upsert path so the agent connects the dots.
    expect($descriptor->description)->toContain('upsert_entry');
    // The literal marker class is what the overlay matches on; the
    // doc should give the agent the exact wrapper to write.
    expect($descriptor->description)->toContain('craft-ai-comment-mark');
    expect($descriptor->description)->toContain('data-craft-ai-comment-id');
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

it('persists a referenceId when the agent pins the comment to a CKEditor span', function () {
    $entry = Entry::factory()->section('posts')->title('Post')->create();

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
        'fieldHandle' => 'bodyContent',
        'referenceId' => 'agent-uuid-001',
        'body' => 'This paragraph buries the lede.',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data']['referenceId'])->toBe('agent-uuid-001');

    $record = CommentRecord::findOne(['id' => $payload['data']['id']]);
    expect($record->referenceId)->toBe('agent-uuid-001');
});

it('errors when the agent passes referenceId without a fieldHandle', function () {
    $entry = Entry::factory()->section('posts')->title('Post')->create();

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
        'referenceId' => 'stray-uuid',
        'body' => 'no field anchor',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('referenceId');
    expect($output->text)->toContain('fieldHandle');
});

it('rejects a whole-field comment on a CKEditor field', function () {
    // CKEditor fields can pin a comment to an individual span, and a
    // field-level dot on long-form prose isn't actionable — the editor
    // can't tell which sentence the feedback is about. Without a
    // referenceId the call must fail and tell the agent to wrap a span.
    $ckeditorField = Field::factory()
        ->name('Body Content')
        ->handle('bodyContent')
        ->type(\craft\ckeditor\Field::class)
        ->create();
    Section::factory()->name('Articles')->handle('articles')->fields($ckeditorField)->create();

    $entry = Entry::factory()->section('articles')->title('Post')->create();

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
        'fieldHandle' => 'bodyContent',
        'body' => 'This whole field is weak.',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('bodyContent');
    expect($output->text)->toContain('referenceId');
    expect($output->text)->toContain('craft-ai-comment-mark');

    // Nothing should have been persisted for the rejected comment.
    expect(CommentRecord::find()->count())->toBe(0);
});

it('allows a span-pinned comment on a CKEditor field', function () {
    $ckeditorField = Field::factory()
        ->name('Body Content')
        ->handle('bodyContent')
        ->type(\craft\ckeditor\Field::class)
        ->create();
    Section::factory()->name('Articles')->handle('articles')->fields($ckeditorField)->create();

    $entry = Entry::factory()->section('articles')->title('Post')->create();

    $output = $this->registry->execute('leave_comment', [
        'entryId' => $entry->id,
        'fieldHandle' => 'bodyContent',
        'referenceId' => 'span-uuid-001',
        'body' => 'This sentence buries the lede.',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data']['fieldHandle'])->toBe('bodyContent');
    expect($payload['data']['referenceId'])->toBe('span-uuid-001');

    $record = CommentRecord::findOne(['id' => $payload['data']['id']]);
    expect($record->referenceId)->toBe('span-uuid-001');
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
