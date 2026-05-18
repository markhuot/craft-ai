<?php

use craft\fields\PlainText;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\tools\GetComments;
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
    $this->registry->register(GetComments::class);
});

function seedComment(int $elementId, array $attrs = []): CommentRecord {
    $record = new CommentRecord();
    $record->sessionId = $attrs['sessionId'] ?? 'sess-get';
    $record->elementId = $elementId;
    $record->isDraft = $attrs['isDraft'] ?? false;
    $record->fieldHandle = $attrs['fieldHandle'] ?? null;
    $record->body = $attrs['body'] ?? 'a comment';
    $record->status = $attrs['status'] ?? CommentRecord::STATUS_OPEN;
    $record->save();
    return $record;
}

it('lists open comments for an entry by default', function () {
    $entry = Entry::factory()->section('posts')->title('E')->create();
    seedComment($entry->id, ['body' => 'first', 'fieldHandle' => 'title']);
    seedComment($entry->id, ['body' => 'second']);
    seedComment($entry->id, ['body' => 'resolved one', 'status' => CommentRecord::STATUS_RESOLVED]);

    $output = $this->registry->execute('get_comments', ['entryId' => $entry->id]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data'])->toHaveCount(2);
    expect($payload['data'][0]['body'])->toBe('first');
});

it('lists all comments when status is "all"', function () {
    $entry = Entry::factory()->section('posts')->title('E')->create();
    seedComment($entry->id, ['body' => 'open']);
    seedComment($entry->id, ['body' => 'resolved', 'status' => CommentRecord::STATUS_RESOLVED]);

    $output = $this->registry->execute('get_comments', [
        'entryId' => $entry->id,
        'status' => 'all',
    ]);

    $payload = decode($output);
    expect($payload['data'])->toHaveCount(2);
});

it('separates draft comments from canonical comments by isDraft', function () {
    $entry = Entry::factory()->section('posts')->title('E')->create();
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    seedComment((int) $entry->id, ['body' => 'on canonical']);
    seedComment((int) $draft->draftId, ['body' => 'on draft', 'isDraft' => true]);

    $canonical = decode($this->registry->execute('get_comments', ['entryId' => $entry->id]));
    $draftOnly = decode($this->registry->execute('get_comments', ['draftId' => $draft->draftId]));

    expect($canonical['data'])->toHaveCount(1);
    expect($canonical['data'][0]['body'])->toBe('on canonical');
    expect($draftOnly['data'])->toHaveCount(1);
    expect($draftOnly['data'][0]['body'])->toBe('on draft');
});

it('includes comments on nested Matrix block entries when scoped to the parent entry', function () {
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
        'title' => 'Post',
        'fields' => [
            'body' => [
                'new1' => ['type' => 'heading', 'fields' => ['headingText' => 'Heading']],
            ],
        ],
    ]));
    $parentId = (int) $created['data']['entry']['id'];
    $parent = \craft\elements\Entry::find()->id($parentId)->status(null)->one();
    $blockId = (int) $parent->body->all()[0]->id;

    seedComment($parentId, ['body' => 'on parent', 'fieldHandle' => 'title']);
    seedComment($blockId, ['body' => 'on block', 'fieldHandle' => 'headingText']);

    $output = $this->registry->execute('get_comments', ['entryId' => $parentId]);
    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);

    $bodies = array_map(fn ($c) => $c['body'], $payload['data']);
    expect($bodies)->toContain('on parent');
    expect($bodies)->toContain('on block');

    // Each row carries elementId so the agent can tell which level the
    // comment lives on.
    $nested = collect($payload['data'])->firstWhere('body', 'on block');
    expect($nested['elementId'])->toBe($blockId);
});

it('errors when neither entryId nor draftId is provided', function () {
    $output = $this->registry->execute('get_comments', []);
    expect($output->isError)->toBeTrue();
});

it('errors on an unknown status value', function () {
    $entry = Entry::factory()->section('posts')->create();
    $output = $this->registry->execute('get_comments', [
        'entryId' => $entry->id,
        'status' => 'archived',
    ]);
    expect($output->isError)->toBeTrue();
});
