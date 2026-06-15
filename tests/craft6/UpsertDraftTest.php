<?php

use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Site\Models\Site as SiteModel;

use craft\elements\Entry;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;

/**
 * Give a seeded section a per-site URI format so entries/drafts in it expose a
 * front-end URL. seedSection()/Section::factory() leaves site settings with a
 * random hasUrls and a null uriFormat, so getUrl() returns null and the
 * preview-token machinery has nothing to tokenize. Forcing a uriFormat here
 * mirrors the URL-bearing sections craft-pest produced under Craft 5.
 */
function enableSectionUrls(string $handle, string $uriFormat = '{slug}'): void
{
    $section = \CraftCms\Cms\Section\Models\Section::query()->where('handle', $handle)->firstOrFail();
    \CraftCms\Cms\Section\Models\SectionSiteSettings::query()
        ->where('sectionId', $section->id)
        ->update(['hasUrls' => true, 'uriFormat' => $uriFormat, 'template' => '_entry']);
    \CraftCms\Cms\Support\Facades\Sections::refreshSections();
}

/**
 * Create a non-primary site through the native factory rather than
 * new Site()+saveSite(): the Craft 6 yii2-adapter's saveSite() does
 * `new Data\Site($legacySite->toArray())` without stripping the `nameRaw`
 * (and uiLabel/languageRaw/…) keys, so the Data\Site constructor throws an
 * UnknownPropertyException. The factory writes the row and refreshes caches.
 */
function makeSecondarySite(string $handle, string $name, string $language): SiteModel
{
    $group = Craft::$app->sites->getAllGroups()[0];

    return SiteModel::factory()->create([
        'groupId' => $group->id,
        'handle' => $handle,
        'name' => $name,
        'language' => $language,
        'primary' => false,
    ]);
}

/**
 * Enable a seeded section on an additional site. Under Craft 5, craft-pest's
 * Section::factory() enabled every existing site; the native Section::factory()
 * only writes a site_settings row for the primary site, so a section never
 * covers a second locale on its own. Add the row explicitly so drafts/entries
 * can be created on that site.
 */
function enableSectionOnSite(string $sectionHandle, int $siteId): void
{
    $section = \CraftCms\Cms\Section\Models\Section::query()->where('handle', $sectionHandle)->firstOrFail();
    \CraftCms\Cms\Section\Models\SectionSiteSettings::query()->firstOrCreate([
        'sectionId' => $section->id,
        'siteId' => $siteId,
    ], [
        'uid' => (string) \CraftCms\Cms\Support\Str::uuid(),
        'hasUrls' => false,
        'uriFormat' => null,
        'template' => null,
    ]);
    \CraftCms\Cms\Support\Facades\Sections::refreshSections();
}

beforeEach(function () {
    seedSection('posts', 'Posts');
    enableSectionUrls('posts');

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(UpsertDraft::class);

    // saveSite() writes to project config outside the test transaction —
    // snapshot existing sites so anything a test creates can be removed.
    $this->preexistingSiteIds = array_map(
        fn ($s) => $s->id,
        Craft::$app->sites->getAllSites(true),
    );
});

afterEach(function () {
    foreach (Craft::$app->sites->getAllSites(true) as $site) {
        if (in_array($site->id, $this->preexistingSiteIds, true)) {
            continue;
        }
        if ($site->primary) {
            continue;
        }
        Craft::$app->sites->deleteSiteById($site->id);
    }
});

function canonicalEntry(ToolRegistry $registry): array
{
    return decode($registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Canonical',
    ]))['data']['entry'];
}

it('creates a draft of a canonical entry', function () {
    $entry = canonicalEntry($this->registry);

    $output = $this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'My Draft',
    ]);

    expect($output->isError)->toBeFalse();
    $draft = decode($output)['data']['draft'];
    expect($draft['title'])->toBe('My Draft');
    expect($draft['draftId'])->not->toBeNull();
    expect($draft['canonicalId'])->toBe($entry['id']);
});

it('sets the draft name and notes on creation', function () {
    $entry = canonicalEntry($this->registry);

    $output = $this->registry->execute('upsert_draft', [
        'entry' => $entry['id'],
        'name' => 'Editorial pass',
        'notes' => 'Tightened the intro',
    ]);

    expect($output->isError)->toBeFalse();
    $created = decode($output)['data']['draft'];

    $draft = Entry::find()->draftId($created['draftId'])->status(null)->one();
    expect($draft->draftName)->toBe('Editorial pass');
    expect($draft->draftNotes)->toBe('Tightened the intro');
});

it('updates an existing draft by draftId', function () {
    $entry = canonicalEntry($this->registry);
    $created = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Original Draft',
    ]))['data']['draft'];

    $output = $this->registry->execute('upsert_draft', [
        'draftId' => $created['draftId'], 'title' => 'Updated Draft',
    ]);

    expect($output->isError)->toBeFalse();
    expect(decode($output)['data']['draft']['draftId'])->toBe($created['draftId']);

    $draft = Entry::find()->draftId($created['draftId'])->status(null)->one();
    expect($draft->title)->toBe('Updated Draft');
});

it('requires entry or section when no draftId is given', function () {
    $result = $this->registry->execute('upsert_draft', ['title' => 'Orphan']);

    expect($result->isError)->toBeTrue();
});

it('creates a fresh draft with no canonical entry from a section', function () {
    $output = $this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
        'name' => 'Initial pass',
    ]);

    expect($output->isError)->toBeFalse();
    $draft = decode($output)['data']['draft'];
    expect($draft['title'])->toBe('Fresh Draft');
    expect($draft['draftId'])->not->toBeNull();

    $reloaded = Entry::find()->draftId($draft['draftId'])->status(null)->one();
    expect($reloaded)->not->toBeNull();
    expect($reloaded->draftName)->toBe('Initial pass');
});

it('errors when section is unknown', function () {
    $result = $this->registry->execute('upsert_draft', [
        'section' => 'does-not-exist',
        'title' => 'Nope',
    ]);

    expect($result->isError)->toBeTrue();
});

it('errors on an unknown draftId', function () {
    $result = $this->registry->execute('upsert_draft', ['draftId' => 999999, 'title' => 'Nope']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('errors on an unknown canonical entry id', function () {
    $result = $this->registry->execute('upsert_draft', ['entry' => 999999, 'title' => 'Nope']);

    expect($result->isError)->toBeTrue();
    expect($result->text)->toContain('999999');
});

it('skips create-only required rules when updating an existing draft', function () {
    $entry = canonicalEntry($this->registry);
    $created = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'],
    ]))['data']['draft'];

    $output = $this->registry->execute('upsert_draft', ['draftId' => $created['draftId']]);

    expect($output->isError)->toBeFalse();
});

it('returns a tokenized preview URL for a draft of a canonical entry', function () {
    $entry = canonicalEntry($this->registry);

    $draft = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'My Draft',
    ]))['data']['draft'];

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($draft['url'])->toContain("$tokenParam=");

    parse_str(parse_url($draft['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route)->not->toBeFalse();
    expect($route[0])->toBe('preview/preview');
    expect($route[1]['draftId'])->toBe($draft['draftId']);
    expect($route[1]['canonicalId'])->toBe($entry['id']);
    expect($route[1]['elementType'])->toBe(Entry::class);
});

it('returns a tokenized preview URL for a fresh draft', function () {
    $draft = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]))['data']['draft'];

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($draft['url'])->toContain("$tokenParam=");

    parse_str(parse_url($draft['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route)->not->toBeFalse();
    expect($route[0])->toBe('preview/preview');
    expect($route[1]['draftId'])->toBe($draft['draftId']);
});

it('returns a tokenized preview URL when updating a draft', function () {
    $entry = canonicalEntry($this->registry);
    $created = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Original',
    ]))['data']['draft'];

    $updated = decode($this->registry->execute('upsert_draft', [
        'draftId' => $created['draftId'], 'title' => 'Updated',
    ]))['data']['draft'];

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($updated['url'])->toContain("$tokenParam=");

    parse_str(parse_url($updated['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route[1]['draftId'])->toBe($updated['draftId']);
});

it('folds the cpEditUrl guidance into _notes when the draft has no front-end URL on CP', function () {
    // No URI format → no front-end URL → no open_preview suggestion.
    // The draft still has a CP edit screen the user can be linked
    // back to, so the envelope surfaces that and omits the preview hint.
    seedSection('hidden', 'Hidden', SectionType::Channel, [], false);

    $payload = decode($this->registry->execute('upsert_draft', [
        'section' => 'hidden',
        'title' => 'Invisible',
    ]));

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->not->toContain('open_preview');
    expect($payload['_notes'])->toContain('review and edit');
    expect($payload['data']['draft']['url'])->toBeNull();
});

it('folds the open_preview prompt into _notes when the draft has a URL', function () {
    $entry = canonicalEntry($this->registry);

    $payload = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Previewable',
    ]));

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['data'])->toHaveKey('draft');
    expect($payload['data'])->not->toHaveKey('notes');

    expect($payload['_notes'])->toContain('open_preview');
    expect($payload['_notes'])->toContain($payload['data']['draft']['url']);
});

it('skips the open_preview prompt on MCP and keeps _notes scoped to the tool narration', function () {
    $entry = canonicalEntry($this->registry);

    /** @var ToolContext $context */
    $context = Craft::$container->get(ToolContext::class);
    $context->begin(null, null, ClientType::MCP);

    $payload = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'MCP Draft',
    ]));

    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->not->toContain('open_preview');
    expect($payload['_notes'])->not->toContain('review and edit');
    expect($payload['_notes'])->toMatch('/draftId=\d+/');
    expect($payload['data']['draft']['title'])->toBe('MCP Draft');
});

it('anchors a canonical-entry draft to the requested site', function () {
    // Spanish has to be in place BEFORE we make the section + entry so
    // the section enables both locales. The beforeEach posts section
    // doesn't qualify — it was created when only the primary existed.
    $spanish = makeSecondarySite('spanish', 'Spanish', 'es');

    $sectionHandle = 'multisite'.bin2hex(random_bytes(3));
    seedSection($sectionHandle, ucfirst($sectionHandle));
    enableSectionOnSite($sectionHandle, $spanish->id);
    $entry = decode($this->registry->execute('upsert_entry', [
        'section' => $sectionHandle, 'title' => 'Canonical',
    ]))['data']['entry'];

    $output = $this->registry->execute('upsert_draft', [
        'entry' => $entry['id'],
        'site' => 'spanish',
        'title' => 'Borrador',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $draft = decode($output)['data']['draft'];
    expect($draft['siteId'])->toBe($spanish->id);
    expect($draft['canonicalId'])->toBe($entry['id']);
});

it('errors clearly when drafting on a site the section is not enabled for', function () {
    // German is added but the section's site_settings only cover the
    // primary, so a draft on German is impossible without first
    // extending the section. The tool should surface this rather than
    // silently land the draft on the primary.
    $german = makeSecondarySite('german', 'German', 'de');

    // `posts` was created in beforeEach BEFORE German existed, so it
    // doesn't enable German.
    $entry = canonicalEntry($this->registry);

    $output = $this->registry->execute('upsert_draft', [
        'entry' => $entry['id'],
        'site' => 'german',
        'title' => 'Entwurf',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('german');
    expect($output->text)->toContain('upsert_section');
});
