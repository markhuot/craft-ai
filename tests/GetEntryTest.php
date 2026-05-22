<?php

use craft\models\Site;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Entry;
use markhuot\craftpest\factories\Section;

beforeEach(function () {
    Section::factory()->name('Posts')->handle('posts')->create();

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
    $entry = Entry::factory()->section('posts')->title('Hello World')->create();

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
    $entry = Entry::factory()->section('posts')->title('Hidden')->enabled(false)->create();

    $output = $this->registry->execute('get_entry', ['id' => $entry->id]);

    expect($output->isError)->toBeFalse();
    $payload = json_decode($output->text, true);
    expect($payload['data']['title'])->toBe('Hidden');
});

it('names the site in the notes so the agent knows which locale it just read', function () {
    $entry = Entry::factory()->section('posts')->title('Hello')->create();

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
    $group = Craft::$app->sites->getAllGroups()[0];
    $spanish = new Site([
        'groupId' => $group->id,
        'handle' => 'spanish',
        'name' => 'Spanish',
        'language' => 'es',
        'primary' => false,
        'hasUrls' => false,
    ]);
    Craft::$app->sites->saveSite($spanish);

    $sectionHandle = 'multisite'.bin2hex(random_bytes(3));
    Section::factory()->name(ucfirst($sectionHandle))->handle($sectionHandle)->create();
    $entry = Entry::factory()->section($sectionHandle)->title('Hello')->create();

    $output = $this->registry->execute('get_entry', [
        'id' => $entry->id,
        'site' => 'spanish',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['siteId'])->toBe($spanish->id);
    expect($payload['_notes'])->toContain('spanish');
});
