<?php

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Entry\Models\EntryType;
use CraftCms\Cms\Field\Entries;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\Field\Models\Field;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\Models\FieldLayout;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Str;
use markhuot\craftai\diff\RevisionDiffService;
use markhuot\craftai\diff\VersionRef;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;

beforeEach(function () {
    $body = seedField('body', 'Body', PlainText::class);
    seedSection('posts', 'Posts', SectionType::Channel, [$body]);
});

function makeRevision(Entry $entry): int
{
    return Craft::$app->getRevisions()->createRevision($entry, null, null, [], force: true);
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

it('resolves version refs scoped to a canonical entry', function () {
    $entry = seedEntry('posts', ['title' => 'Hello']);
    $rev = makeRevision($entry);

    $current = VersionRef::resolve($entry->id, 'current');
    expect($current)->not->toBeNull();
    expect($current->id)->toBe($entry->id);
    expect($current->getIsRevision())->toBeFalse();

    $revEntry = VersionRef::resolve($entry->id, 'rev:'.$rev);
    expect($revEntry)->not->toBeNull();
    expect($revEntry->getIsRevision())->toBeTrue();

    // A bare integer is shorthand for a revisionId.
    expect(VersionRef::resolve($entry->id, (string) $rev))->not->toBeNull();

    // A revision belonging to a different canonical does not resolve.
    expect(VersionRef::resolve(999999, 'rev:'.$rev))->toBeNull();

    // Garbage refs resolve to null rather than throwing.
    expect(VersionRef::resolve($entry->id, 'nonsense'))->toBeNull();
});

it('diffs text fields with word-level granularity', function () {
    $entry = seedEntry('posts', ['title' => 'First Title']);
    $entry->setFieldValue('body', 'The quick brown fox');
    Craft::$app->elements->saveElement($entry);
    $rev1 = makeRevision($entry);

    $entry->title = 'Second Title';
    $entry->setFieldValue('body', 'The slow brown fox jumps');
    Craft::$app->elements->saveElement($entry);
    $rev2 = makeRevision($entry);

    $a = VersionRef::resolve($entry->id, 'rev:'.$rev1);
    $b = VersionRef::resolve($entry->id, 'rev:'.$rev2);

    $diff = (new RevisionDiffService())->diff($a, $b);

    expect($diff['summary']['changed'])->toBeGreaterThanOrEqual(2);
    expect($diff['a']['ref'])->toBe('rev:'.$rev1);
    expect($diff['b']['ref'])->toBe('rev:'.$rev2);

    $byHandle = collect($diff['fields'])->keyBy('handle');

    expect($byHandle->get('title')['status'])->toBe('changed');
    expect($byHandle->get('title')['kind'])->toBe('text');

    $body = $byHandle->get('body');
    expect($body['status'])->toBe('changed');
    expect($body['kind'])->toBe('text');

    $segments = $body['detail']['textDiff'];
    $del = implode('', array_map(fn ($s) => $s['op'] === 'del' ? $s['text'] : '', $segments));
    $ins = implode('', array_map(fn ($s) => $s['op'] === 'ins' ? $s['text'] : '', $segments));
    expect($del)->toContain('quick');
    expect($ins)->toContain('slow');
    expect($ins)->toContain('jumps');
});

it('marks unchanged fields as unchanged across two identical revisions', function () {
    $entry = seedEntry('posts', ['title' => 'Same']);
    $entry->setFieldValue('body', 'Unchanging body');
    Craft::$app->elements->saveElement($entry);
    $rev1 = makeRevision($entry);
    $rev2 = makeRevision($entry);

    $a = VersionRef::resolve($entry->id, 'rev:'.$rev1);
    $b = VersionRef::resolve($entry->id, 'rev:'.$rev2);
    $diff = (new RevisionDiffService())->diff($a, $b);

    $byHandle = collect($diff['fields'])->keyBy('handle');
    expect($byHandle->get('body')['status'])->toBe('unchanged');
    expect($byHandle->get('title')['status'])->toBe('unchanged');
    expect($diff['summary']['changed'])->toBe(0);
});

it('diffs a revision against the current canonical', function () {
    $entry = seedEntry('posts', ['title' => 'Original']);
    $entry->setFieldValue('body', 'first');
    Craft::$app->elements->saveElement($entry);
    $rev1 = makeRevision($entry);

    $entry->setFieldValue('body', 'second');
    Craft::$app->elements->saveElement($entry);

    $a = VersionRef::resolve($entry->id, 'rev:'.$rev1);
    $b = VersionRef::resolve($entry->id, 'current');
    $diff = (new RevisionDiffService())->diff($a, $b);

    expect($diff['b']['ref'])->toBe('current');
    expect($diff['b']['label'])->toBe('Current');
    $byHandle = collect($diff['fields'])->keyBy('handle');
    expect($byHandle->get('body')['status'])->toBe('changed');
});

it('diffs relation fields by added/removed related elements', function () {
    // Handles for sections/fields live in project config, which isn't rolled
    // back between tests, so randomize them to avoid cross-test collisions.
    $tags = 'tags'.bin2hex(random_bytes(3));
    $stories = 'stories'.bin2hex(random_bytes(3));
    $relHandle = 'rel'.bin2hex(random_bytes(3));

    seedSection($tags, ucfirst($tags));

    $related = new Entries(['name' => 'Related', 'handle' => $relHandle, 'sources' => '*']);
    Craft::$app->getFields()->saveField($related);
    seedSection($stories, ucfirst($stories), SectionType::Channel, [$related]);

    $t1 = seedEntry($tags, ['title' => 'Tag One']);
    $t2 = seedEntry($tags, ['title' => 'Tag Two']);

    $entry = seedEntry($stories, ['title' => 'Story']);
    $entry->setFieldValue($relHandle, [$t1->id]);
    Craft::$app->elements->saveElement($entry);
    $rev1 = makeRevision($entry);

    $entry->setFieldValue($relHandle, [$t1->id, $t2->id]);
    Craft::$app->elements->saveElement($entry);
    $rev2 = makeRevision($entry);

    $a = VersionRef::resolve($entry->id, 'rev:'.$rev1);
    $b = VersionRef::resolve($entry->id, 'rev:'.$rev2);
    $diff = (new RevisionDiffService())->diff($a, $b);

    $rel = collect($diff['fields'])->firstWhere('handle', $relHandle);
    expect($rel)->not->toBeNull();
    expect($rel['status'])->toBe('changed');
    expect($rel['kind'])->toBe('relation');

    $addedIds = collect($rel['detail']['added'])->pluck('id')->all();
    expect($addedIds)->toContain($t2->id);
    expect(collect($rel['detail']['added'])->pluck('title')->all())->toContain('Tag Two');
    expect($rel['detail']['removed'])->toBe([]);
});

it('matches matrix blocks across revisions by canonical id', function () {
    $sfx = bin2hex(random_bytes(3));
    $matrixHandle = 'builder'.$sfx;
    $sectionHandle = 'articles'.$sfx;
    $textType = 'textBlock'.$sfx;
    $headingType = 'headingBlock'.$sfx;

    $bodyCopy = seedField('bodyCopy'.$sfx, 'Body Copy '.$sfx, PlainText::class);
    $textBlock = seedBlockEntryType('Text Block '.$sfx, $textType, [$bodyCopy]);
    $headingTxt = seedField('headingTxt'.$sfx, 'Heading Text '.$sfx, PlainText::class);
    $headingBlock = seedBlockEntryType('Heading Block '.$sfx, $headingType, [$headingTxt]);
    $matrix = seedMatrixField('Builder '.$sfx, $matrixHandle, [$textBlock, $headingBlock]);
    seedSection($sectionHandle, 'Articles '.$sfx, SectionType::Channel, [$matrix]);

    $registry = new ToolRegistry();
    $registry->register(UpsertEntry::class);

    $created = decode($registry->execute('upsert_entry', [
        'section' => $sectionHandle,
        'title' => 'Builder Post',
        'fields' => [$matrixHandle => [
            'new1' => ['type' => $textType, 'fields' => ['bodyCopy'.$sfx => 'alpha']],
            'new2' => ['type' => $textType, 'fields' => ['bodyCopy'.$sfx => 'beta']],
        ]],
    ]))['data']['entry'];

    $entry = Entry::find()->id($created['id'])->status(null)->one();
    $rev1 = makeRevision($entry);

    $blocks = $entry->getFieldValue($matrixHandle)->all();
    $b1 = $blocks[0]->id;
    $b2 = $blocks[1]->id;

    // Re-submit the two original text blocks UNCHANGED (same ids, same content)
    // and append a brand-new heading block. This isolates the behavior under
    // test — canonical-id matching of unchanged blocks — from any text-diff of
    // a block's own content. (Mutating a block's body here made the expected
    // row count depend on whether the revision snapshot captured the pre- or
    // post-update value of that block, which was the source of the flakiness.)
    $registry->execute('upsert_entry', [
        'id' => $created['id'],
        'fields' => [$matrixHandle => [
            (string) $b1 => ['type' => $textType, 'fields' => ['bodyCopy'.$sfx => 'alpha']],
            (string) $b2 => ['type' => $textType, 'fields' => ['bodyCopy'.$sfx => 'beta']],
            'new1' => ['type' => $headingType, 'fields' => ['headingTxt'.$sfx => 'New Heading']],
        ]],
    ]);

    $entry2 = Entry::find()->id($created['id'])->status(null)->one();
    $rev2 = makeRevision($entry2);

    $a = VersionRef::resolve($created['id'], 'rev:'.$rev1);
    $b = VersionRef::resolve($created['id'], 'rev:'.$rev2);
    $diff = (new RevisionDiffService())->diff($a, $b);

    $builder = collect($diff['fields'])->firstWhere('handle', $matrixHandle);
    expect($builder)->not->toBeNull();
    expect($builder['status'])->toBe('changed');

    $blockRows = $builder['detail']['blocks'];
    // The two original text blocks are matched by canonical id across the two
    // revisions and omitted (no false add+remove churn); only the newly added
    // heading block surfaces, as "added".
    expect($blockRows)->toHaveCount(1);
    expect($blockRows[0]['status'])->toBe('added');
    expect($blockRows[0]['type'])->toBe($headingType);
});
