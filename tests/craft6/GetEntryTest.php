<?php

use CraftCms\Cms\Site\Models\Site as SiteModel;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;

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

    $this->registry = new ToolRegistry();
    $this->registry->register(GetEntry::class);

    // Snapshot site IDs so a Spanish site created mid-test gets torn
    // down — saveSite writes to project config outside the test
    // transaction.
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

it('returns full entry details by ID', function () {
    $entry = seedEntry('posts', ['title' => 'Hello World']);

    $output = $this->registry->execute('get_entry', ['id' => $entry->id]);

    expect($output->isError)->toBeFalse();
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKeys(['_notes', 'data']);
    expect($payload['_notes'])->toBeString()->not->toBe('');
    expect($payload['data']['id'])->toBe($entry->id);
    expect($payload['data']['title'])->toBe('Hello World');
});

it('returns an error when the entry does not exist', function () {
    $output = $this->registry->execute('get_entry', ['id' => 999999]);

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('999999');
});

it('finds disabled entries by ID', function () {
    $entry = seedEntry('posts', ['title' => 'Hidden', 'enabled' => false]);

    $output = $this->registry->execute('get_entry', ['id' => $entry->id]);

    expect($output->isError)->toBeFalse();
    $payload = json_decode($output->text, true);
    expect($payload['data']['title'])->toBe('Hidden');
});

it('names the site in the notes so the agent knows which locale it just read', function () {
    $entry = seedEntry('posts', ['title' => 'Hello']);

    $output = $this->registry->execute('get_entry', ['id' => $entry->id]);

    expect($output->isError)->toBeFalse();
    $payload = json_decode($output->text, true);
    expect($payload['_notes'])->toContain('on site');
    $primary = Craft::$app->sites->getPrimarySite();
    expect($payload['_notes'])->toContain($primary->handle);
});

it('returns the entry as it exists on the requested site', function () {
    // Spanish site has to exist BEFORE the section is created so the
    // section's site_settings cover both locales — otherwise the entry
    // has no Spanish row to query.
    $spanish = makeSecondarySite('spanish', 'Spanish', 'es');

    $sectionHandle = 'multisite'.bin2hex(random_bytes(3));
    seedSection($sectionHandle, ucfirst($sectionHandle));
    enableSectionOnSite($sectionHandle, $spanish->id);
    $entry = seedEntry($sectionHandle, ['title' => 'Hello']);

    // seedEntry()'s factory save only lands the primary-site row; re-saving
    // through the elements service propagates the entry to every site the
    // section now enables (propagationMethod defaults to All), giving us a
    // Spanish row to read back.
    Craft::$app->elements->saveElement($entry, false, true, false);

    $output = $this->registry->execute('get_entry', [
        'id' => $entry->id,
        'site' => 'spanish',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['siteId'])->toBe($spanish->id);
    expect($payload['_notes'])->toContain('spanish');
});
