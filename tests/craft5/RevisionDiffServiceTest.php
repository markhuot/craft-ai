<?php

use craft\fields\Entries;
use craft\fields\PlainText;
use markhuot\craftai\diff\RevisionDiffService;
use markhuot\craftai\diff\VersionRef;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\EntryType;
use markhuot\craftpest\factories\Field;
use markhuot\craftpest\factories\MatrixField as MatrixFieldFactory;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $body = Field::factory()->name('Body')->handle('body')->type(PlainText::class);
    Section::factory()->name('Posts')->handle('posts')->fields($body)->create();
});

function makeRevision(\craft\elements\Entry $entry): int
{
    return Craft::$app->getRevisions()->createRevision($entry, null, null, [], force: true);
}

it('resolves version refs scoped to a canonical entry', function () {
    $entry = Entry::factory()->section('posts')->title('Hello')->create();
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
    $entry = Entry::factory()->section('posts')->title('First Title')->create();
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
    $entry = Entry::factory()->section('posts')->title('Same')->create();
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
    $entry = Entry::factory()->section('posts')->title('Original')->create();
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

    Section::factory()->name(ucfirst($tags))->handle($tags)->create();

    $related = new Entries(['name' => 'Related', 'handle' => $relHandle, 'sources' => '*']);
    Craft::$app->getFields()->saveField($related);
    Section::factory()->name(ucfirst($stories))->handle($stories)->fields($related)->create();

    $t1 = Entry::factory()->section($tags)->title('Tag One')->create();
    $t2 = Entry::factory()->section($tags)->title('Tag Two')->create();

    $entry = Entry::factory()->section($stories)->title('Story')->create();
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

    $bodyCopy = Field::factory()->name('Body Copy '.$sfx)->handle('bodyCopy'.$sfx)->type(PlainText::class);
    $textBlock = EntryType::factory()->name('Text Block '.$sfx)->handle($textType)->hasTitleField(false)->fields($bodyCopy);
    $headingTxt = Field::factory()->name('Heading Text '.$sfx)->handle('headingTxt'.$sfx)->type(PlainText::class);
    $headingBlock = EntryType::factory()->name('Heading Block '.$sfx)->handle($headingType)->hasTitleField(false)->fields($headingTxt);
    $matrix = MatrixFieldFactory::factory()->name('Builder '.$sfx)->handle($matrixHandle)->entryTypes($textBlock, $headingBlock)->create();
    Section::factory()->name('Articles '.$sfx)->handle($sectionHandle)->fields($matrix)->create();

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

    $entry = \craft\elements\Entry::find()->id($created['id'])->status(null)->one();
    $rev1 = makeRevision($entry);

    $blocks = $entry->getFieldValue($matrixHandle)->all();
    $b1 = $blocks[0]->id;
    $b2 = $blocks[1]->id;

    $registry->execute('upsert_entry', [
        'id' => $created['id'],
        'fields' => [$matrixHandle => [
            (string) $b1 => ['type' => $textType, 'fields' => ['bodyCopy'.$sfx => 'alpha CHANGED']],
            (string) $b2 => ['type' => $textType, 'fields' => ['bodyCopy'.$sfx => 'beta']],
            'new1' => ['type' => $headingType, 'fields' => ['headingTxt'.$sfx => 'New Heading']],
        ]],
    ]);

    $entry2 = \craft\elements\Entry::find()->id($created['id'])->status(null)->one();
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
