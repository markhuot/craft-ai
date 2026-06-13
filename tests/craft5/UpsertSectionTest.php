<?php

use craft\models\Site;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertSection;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertSection::class);
    // Snapshot existing site IDs so afterEach can drop anything the test
    // adds — saveSite() writes to project config outside the test
    // transaction, so leaked rows would poison later test files.
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

function makeSpanishSite(): Site
{
    $group = Craft::$app->sites->getAllGroups()[0];
    $site = new Site([
        'groupId' => $group->id,
        'handle' => 'spanish',
        'name' => 'Spanish',
        'language' => 'es',
        'primary' => false,
        'hasUrls' => false,
    ]);
    Craft::$app->sites->saveSite($site);

    return $site;
}

it('updates an existing section without requiring entryTypes', function () {
    $section = Section::factory()->name('News')->handle('news')->create();

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'enableVersioning' => false,
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $reloaded = Craft::$app->entries->getSectionById($section->id);
    expect($reloaded->enableVersioning)->toBeFalse();
    expect($reloaded->getEntryTypes())->not->toBeEmpty();
});

it('rejects an empty entryTypes list when explicitly passed', function () {
    $section = Section::factory()->name('News')->handle('news')->create();

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'entryTypes' => [],
    ]);

    expect($output->isError)->toBeTrue();
});

it('requires entryTypes when creating', function () {
    $output = $this->registry->execute('upsert_section', [
        'name' => 'Posts',
        'handle' => 'posts',
        'type' => 'channel',
    ]);

    expect($output->isError)->toBeTrue();
});

it('enables a new site for an existing section via the sites parameter', function () {
    $h = 'multisite'.bin2hex(random_bytes(3));
    $section = Section::factory()->name(ucfirst($h))->handle($h)->create();
    makeSpanishSite();

    // Confirm the section was created before the Spanish site, so it
    // doesn't yet enable it.
    $before = collect(Craft::$app->entries->getSectionById($section->id)->getSiteSettings())
        ->pluck('siteId')
        ->all();
    expect($before)->not->toContain(Craft::$app->sites->getSiteByHandle('spanish')->id);

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'sites' => ['default', 'spanish'],
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $after = collect(Craft::$app->entries->getSectionById($section->id)->getSiteSettings())
        ->pluck('siteId')
        ->all();
    expect($after)->toContain(Craft::$app->sites->getSiteByHandle('spanish')->id);
    expect($after)->toContain(Craft::$app->sites->getSiteByHandle('default')->id);
});

it('drops a site from a section when the sites list omits it', function () {
    makeSpanishSite();
    $h = 'multisite'.bin2hex(random_bytes(3));
    $section = Section::factory()->name(ucfirst($h))->handle($h)->create();

    // Verify it starts enabled on both sites (factory enables every site).
    $before = collect(Craft::$app->entries->getSectionById($section->id)->getSiteSettings())
        ->pluck('siteId')
        ->all();
    expect($before)->toHaveCount(2);

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'sites' => ['default'],
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $after = collect(Craft::$app->entries->getSectionById($section->id)->getSiteSettings())
        ->pluck('siteId')
        ->all();
    expect($after)->toHaveCount(1);
    expect($after)->not->toContain(Craft::$app->sites->getSiteByHandle('spanish')->id);
});

it('preserves per-site uriFormat on existing rows when the sites list is unchanged', function () {
    $section = Section::factory()
        ->name('News')->handle('news')
        ->hasUrls(true)
        ->uriFormat('news/{slug}')
        ->template('news/_entry')
        ->create();

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'enableVersioning' => false,
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $reloaded = Craft::$app->entries->getSectionById($section->id);
    foreach ($reloaded->getSiteSettings() as $row) {
        expect($row->uriFormat)->toBe('news/{slug}');
        expect($row->template)->toBe('news/_entry');
    }
});

it('rejects an unknown site handle in the sites list', function () {
    $section = Section::factory()->name('News')->handle('news')->create();

    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'sites' => ['default', 'no-such-site'],
    ]);

    expect($output->isError)->toBeTrue();
});

it('lists enabled sites in the response notes and warns about missing ones', function () {
    makeSpanishSite();
    $section = Section::factory()->name('News')->handle('news')->create();

    // Reduce the section to only `default` so Spanish is "missing".
    $output = $this->registry->execute('upsert_section', [
        'id' => (string) $section->id,
        'sites' => ['default'],
    ]);

    expect($output->isError)->toBeFalse($output->text);
    expect($output->text)->toContain('Currently enabled on sites: [default]');
    expect($output->text)->toContain('not enabled for this section: [spanish]');
});
