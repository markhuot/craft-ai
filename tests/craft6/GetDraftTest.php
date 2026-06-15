<?php

use CraftCms\Cms\Site\Models\Site as SiteModel;
use markhuot\craftai\tools\GetDraft;
use markhuot\craftai\tools\GetDrafts;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;

/**
 * Give a seeded section a per-site URI format so entries/drafts expose a
 * front-end URL — seedSection() leaves a random hasUrls and a null uriFormat,
 * so getUrl() (and the preview-token machinery) has nothing to work with.
 * (Each craft6 test file runs in its own process, so this helper is redefined
 * per file rather than shared.)
 */
if (! function_exists('enableSectionUrls')) {
    function enableSectionUrls(string $handle, string $uriFormat = '{slug}'): void
    {
        $section = \CraftCms\Cms\Section\Models\Section::query()->where('handle', $handle)->firstOrFail();
        \CraftCms\Cms\Section\Models\SectionSiteSettings::query()
            ->where('sectionId', $section->id)
            ->update(['hasUrls' => true, 'uriFormat' => $uriFormat, 'template' => '_entry']);
        \CraftCms\Cms\Support\Facades\Sections::refreshSections();
    }
}

/**
 * Create a non-primary site through the native factory — the Craft 6
 * yii2-adapter saveSite() does `new Data\Site($legacy->toArray())` without
 * stripping `nameRaw`, so it throws UnknownPropertyException.
 */
if (! function_exists('makeSecondarySite')) {
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
}

/**
 * Enable a seeded section on an additional site — native Section::factory()
 * only writes a site_settings row for the primary site.
 */
if (! function_exists('enableSectionOnSite')) {
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
}

beforeEach(function () {
    seedSection('posts', 'Posts');
    enableSectionUrls('posts');

    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertEntry::class);
    $this->registry->register(UpsertDraft::class);
    $this->registry->register(GetEntry::class);
    $this->registry->register(GetDraft::class);
    $this->registry->register(GetDrafts::class);

    // saveSite() writes project config outside the test transaction;
    // snapshot existing sites so the cleanup removes any added.
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

it('round-trips a fresh draft via get_draft', function () {
    $created = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]))['data']['draft'];

    expect($created['draftId'])->not->toBeNull();

    $output = $this->registry->execute('get_draft', ['draftId' => $created['draftId']]);

    expect($output->isError)->toBeFalse();
    $payload = decode($output);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
    $fetched = $payload['data'];
    expect($fetched['draftId'])->toBe($created['draftId']);
    expect($fetched['title'])->toBe('Fresh Draft');
});

it('round-trips a draft of a canonical entry via get_draft', function () {
    $entry = decode($this->registry->execute('upsert_entry', [
        'section' => 'posts', 'title' => 'Canonical',
    ]))['data']['entry'];
    $draft = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'], 'title' => 'Editorial',
    ]))['data']['draft'];

    $fetched = decode($this->registry->execute('get_draft', ['draftId' => $draft['draftId']]))['data'];

    expect($fetched['draftId'])->toBe($draft['draftId']);
    expect($fetched['canonicalId'])->toBe($entry['id']);
    expect($fetched['title'])->toBe('Editorial');
});

it('errors when get_draft is given an unknown draftId', function () {
    $output = $this->registry->execute('get_draft', ['draftId' => 999999]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});

it('does not return a draft from get_entry', function () {
    $created = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]))['data']['draft'];

    $output = $this->registry->execute('get_entry', ['id' => $created['id']]);

    expect($output->isError)->toBeTrue();
});

it('names the site in the notes so the agent knows which locale it just read', function () {
    $created = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Fresh Draft',
    ]))['data']['draft'];

    $output = $this->registry->execute('get_draft', ['draftId' => $created['draftId']]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['_notes'])->toContain('on site');
    $primary = Craft::$app->sites->getPrimarySite();
    expect($payload['_notes'])->toContain($primary->handle);
});

it('returns the draft as it exists on the requested site', function () {
    // Spanish has to be in place BEFORE the section + canonical so the
    // section's site_settings cover both locales — same constraint as
    // GetEntry's per-site test.
    $spanish = makeSecondarySite('spanish', 'Spanish', 'es');

    $sectionHandle = 'multisite'.bin2hex(random_bytes(3));
    seedSection($sectionHandle, ucfirst($sectionHandle));
    enableSectionOnSite($sectionHandle, $spanish->id);

    $entry = decode($this->registry->execute('upsert_entry', [
        'section' => $sectionHandle, 'title' => 'Canonical',
    ]))['data']['entry'];

    $draft = decode($this->registry->execute('upsert_draft', [
        'entry' => $entry['id'],
        'site' => 'spanish',
        'title' => 'Borrador',
    ]))['data']['draft'];

    // Without site, the primary view comes back.
    $primaryView = decode($this->registry->execute('get_draft', [
        'draftId' => $draft['draftId'],
    ]))['data'];
    expect($primaryView['siteId'])->toBe(Craft::$app->sites->getPrimarySite()->id);

    // With site=spanish, the Spanish row comes back.
    $output = $this->registry->execute('get_draft', [
        'draftId' => $draft['draftId'],
        'site' => 'spanish',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data']['siteId'])->toBe($spanish->id);
    expect($payload['_notes'])->toContain('spanish');
});

it('returns a tokenized preview URL routed to the draft', function () {
    $created = decode($this->registry->execute('upsert_draft', [
        'section' => 'posts',
        'title' => 'Drafted',
    ]))['data']['draft'];

    $fetched = decode($this->registry->execute('get_draft', ['draftId' => $created['draftId']]))['data'];

    $tokenParam = Craft::$app->getConfig()->getGeneral()->tokenParam;
    expect($fetched['url'])->toContain("$tokenParam=");

    parse_str(parse_url($fetched['url'], PHP_URL_QUERY), $query);
    $route = Craft::$app->getTokens()->getTokenRoute($query[$tokenParam]);

    expect($route)->not->toBeFalse();
    expect($route[0])->toBe('preview/preview');
    expect($route[1]['draftId'])->toBe($created['draftId']);
});
