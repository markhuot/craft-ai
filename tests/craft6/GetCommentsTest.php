<?php

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Entry\Models\EntryType;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\Models\Field;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\Models\FieldLayout;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Str;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\tools\GetComments;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;

beforeEach(function () {
    seedSection('posts', 'Posts');

    $this->registry = new ToolRegistry();
    $this->registry->register(GetComments::class);
});

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
    $entry = seedEntry('posts', ['title' => 'Entry']);
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
    $entry = seedEntry('posts', ['title' => 'Entry']);
    seedComment($entry->id, ['body' => 'open']);
    seedComment($entry->id, ['body' => 'resolved', 'status' => CommentRecord::STATUS_RESOLVED]);

    $output = $this->registry->execute('get_comments', [
        'entryId' => $entry->id,
        'status' => 'all',
    ]);

    $payload = decode($output);
    expect($payload['data'])->toHaveCount(2);
});

it('scopes comments strictly to each side of the draft/canonical pair', function () {
    // `CommentScope::pairsFor` does NOT bridge draft↔canonical: a comment
    // authored on the draft is in-flight feedback about that working copy
    // and must not leak onto the live entry (or vice versa). Each surface
    // returns only the comments anchored to its own identity.
    $entry = seedEntry('posts', ['title' => 'Entry']);
    $draft = \Craft::$app->drafts->createDraft($entry, 1);

    seedComment((int) $entry->id, ['body' => 'on canonical']);
    seedComment((int) $draft->draftId, ['body' => 'on draft', 'isDraft' => true]);

    $fromCanonical = decode($this->registry->execute('get_comments', ['entryId' => $entry->id]));
    $fromDraft = decode($this->registry->execute('get_comments', ['draftId' => $draft->draftId]));

    expect($fromCanonical['data'])->toHaveCount(1);
    expect($fromDraft['data'])->toHaveCount(1);

    // The canonical view sees only the canonical-anchored comment.
    expect($fromCanonical['data'][0]['body'])->toBe('on canonical');
    expect($fromCanonical['data'][0]['isDraft'])->toBeFalse();

    // The draft view sees only the draft-anchored comment.
    expect($fromDraft['data'][0]['body'])->toBe('on draft');
    expect($fromDraft['data'][0]['isDraft'])->toBeTrue();
});

it('includes comments on nested Matrix block entries when scoped to the parent entry', function () {
    $headingField = seedField('headingText', 'Heading Text', PlainText::class);
    $headingBlock = seedBlockEntryType('Heading', 'heading', [$headingField]);
    $matrix = seedMatrixField('Body', 'body', [$headingBlock]);
    seedSection('blog', 'Blog', SectionType::Channel, [$matrix]);

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
    $parent = Entry::find()->id($parentId)->status(null)->one();
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
    $entry = seedEntry('posts', []);
    $output = $this->registry->execute('get_comments', [
        'entryId' => $entry->id,
        'status' => 'archived',
    ]);
    expect($output->isError)->toBeTrue();
});
