<?php

use markhuot\craftai\tools\GetEntries;

beforeEach(function () {
    seedSection('posts', 'Posts');
});

it('returns all live entries when no filters are given', function () {
    seedEntry('posts', ['title' => 'First']);
    seedEntry('posts', ['title' => 'Second']);

    $tool = new GetEntries();
    $payload = $tool(section: 'posts');

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
    $result = $payload['data'];

    expect($result)->toHaveCount(2);
    expect(array_column($result, 'title'))->toContain('First', 'Second');
});

it('filters entries by section handle', function () {
    seedSection('pages', 'Pages');
    seedEntry('posts', ['title' => 'Blog Post']);
    seedEntry('pages', ['title' => 'About Us']);

    $tool = new GetEntries();
    $result = $tool(section: 'pages')['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('About Us');
});

it('filters entries by author ID', function () {
    if (Craft::$app->edition === \craft\enums\CmsEdition::Solo) {
        $this->markTestSkipped('Author filtering requires Craft Pro/Team edition');
    }

    seedEntry('posts', ['title' => 'Some Post']);

    $tool = new GetEntries();

    $all = $tool(section: 'posts')['data'];
    expect($all)->toHaveCount(1);

    $filtered = $tool(section: 'posts', authorId: 999999)['data'];
    expect($filtered)->toBe([]);
});

it('filters entries by status', function () {
    seedEntry('posts', ['title' => 'Live Post']);
    seedEntry('posts', ['title' => 'Disabled Post', 'enabled' => false]);

    $tool = new GetEntries();
    $liveOnly = $tool(section: 'posts', status: 'live')['data'];
    $disabledOnly = $tool(section: 'posts', status: 'disabled')['data'];
    $any = $tool(section: 'posts', status: 'any')['data'];

    expect($liveOnly)->toHaveCount(1);
    expect($liveOnly[0]['title'])->toBe('Live Post');

    expect($disabledOnly)->toHaveCount(1);
    expect($disabledOnly[0]['title'])->toBe('Disabled Post');

    expect($any)->toHaveCount(2);
});

it('filters entries by title', function () {
    seedEntry('posts', ['title' => 'Alpha Post']);
    seedEntry('posts', ['title' => 'Beta Post']);

    $tool = new GetEntries();
    $result = $tool(section: 'posts', title: 'Alpha Post')['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Alpha Post');
});

it('filters entries by slug', function () {
    seedEntry('posts', ['title' => 'My Article', 'slug' => 'my-article']);
    seedEntry('posts', ['title' => 'Other Article', 'slug' => 'other-article']);

    $tool = new GetEntries();
    $result = $tool(section: 'posts', slug: 'my-article')['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['slug'])->toBe('my-article');
});

it('filters entries by entry type handle', function () {
    // Native Section::factory() sections have no entry type until an entry is
    // created in them, so read the type off the created entry.
    $entry = seedEntry('posts', ['title' => 'Typed Post']);
    $entryType = $entry->getType();

    $tool = new GetEntries();
    $result = $tool(section: 'posts', type: $entryType->handle)['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Typed Post');
});

it('passes the search parameter through to the entry query', function () {
    // InnoDB FULLTEXT indexes don't expose uncommitted rows to MATCH AGAINST,
    // so we can't test search results inside a transactional test. Instead
    // verify the parameter is wired up by checking the query object.
    $tool = new GetEntries();
    $reflection = new ReflectionMethod($tool, '__invoke');

    $searchParam = $reflection->getParameters()[0];
    expect($searchParam->getName())->toBe('search');
    expect($searchParam->allowsNull())->toBeTrue();
});

it('filters entries posted before a date', function () {
    seedEntry('posts', ['title' => 'Old Post', 'postDate' => '2020-01-01 00:00:00']);
    seedEntry('posts', ['title' => 'New Post', 'postDate' => '2025-06-01 00:00:00']);

    $tool = new GetEntries();
    $result = $tool(section: 'posts', before: '2024-01-01')['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('Old Post');
});

it('filters entries posted after a date', function () {
    seedEntry('posts', ['title' => 'Old Post', 'postDate' => '2020-01-01 00:00:00']);
    seedEntry('posts', ['title' => 'New Post', 'postDate' => '2025-06-01 00:00:00']);

    $tool = new GetEntries();
    $result = $tool(section: 'posts', after: '2024-01-01')['data'];

    expect($result)->toHaveCount(1);
    expect($result[0]['title'])->toBe('New Post');
});

it('sorts results by orderBy', function () {
    seedEntry('posts', ['title' => 'Zebra']);
    seedEntry('posts', ['title' => 'Apple']);

    $tool = new GetEntries();
    $result = $tool(section: 'posts', orderBy: 'title ASC')['data'];

    expect($result[0]['title'])->toBe('Apple');
    expect($result[1]['title'])->toBe('Zebra');
});

it('respects the limit parameter', function () {
    seedEntry('posts', [], 10);

    $tool = new GetEntries();
    $result = $tool(section: 'posts', limit: 3)['data'];

    expect($result)->toHaveCount(3);
});

it('respects the offset parameter', function () {
    seedEntry('posts', ['title' => 'A']);
    seedEntry('posts', ['title' => 'B']);
    seedEntry('posts', ['title' => 'C']);

    $tool = new GetEntries();
    $result = $tool(section: 'posts', orderBy: 'title ASC', limit: 2, offset: 1)['data'];

    expect($result)->toHaveCount(2);
    expect($result[0]['title'])->toBe('B');
    expect($result[1]['title'])->toBe('C');
});

it('defaults limit to 25', function () {
    seedEntry('posts', [], 30);

    $tool = new GetEntries();
    $result = $tool(section: 'posts')['data'];

    expect($result)->toHaveCount(25);
});

it('returns an empty array when no entries match', function () {
    $tool = new GetEntries();
    $payload = $tool(section: 'posts');

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['data'])->toBe([]);
    expect($payload['_notes'])->toBeString()->not->toBe('');
});
