<?php

use craft\models\Site;
use markhuot\craftai\tools\GetSites;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(GetSites::class);
    // Snapshot existing site IDs so afterEach can drop anything the test
    // adds. Tracking IDs by pushing into a Pest test property doesn't
    // work — those go through __set magic which silently drops array
    // appends ("indirect modification of overloaded property").
    $this->preexistingSiteIds = array_map(
        fn ($s) => $s->id,
        Craft::$app->sites->getAllSites(true),
    );
});

afterEach(function () {
    // saveSite() writes to project config (file-backed) outside the
    // test transaction, so a leaked site here would poison every
    // subsequent test that calls Section::factory() — those try to
    // populate sections_sites for every known site and hit a NOT NULL
    // violation on siteId once the project config drifts.
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

function createSite(string $handle, string $language, string $name): Site
{
    $group = Craft::$app->sites->getAllGroups()[0];
    $site = new Site([
        'groupId' => $group->id,
        'handle' => $handle,
        'name' => $name,
        'language' => $language,
        'primary' => false,
        'hasUrls' => false,
    ]);
    Craft::$app->sites->saveSite($site);

    return $site;
}

it('returns every site by default with language and group info', function () {
    createSite('es', 'es-ES', 'Spanish');
    createSite('fr', 'fr-FR', 'French');

    $output = $this->registry->execute('get_sites', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('es');
    expect($handles)->toContain('fr');

    $spanish = collect($payload['data'])->firstWhere('handle', 'es');
    expect($spanish['language'])->toBe('es-ES');
    expect($spanish['groupId'])->not->toBeNull();
    expect($spanish['groupName'])->not->toBeNull();
});

it('filters by language', function () {
    createSite('es', 'es-ES', 'Spanish');
    createSite('fr', 'fr-FR', 'French');

    $output = $this->registry->execute('get_sites', ['language' => 'fr-FR']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);

    $handles = array_column($payload['data'], 'handle');
    expect($handles)->toContain('fr');
    expect($handles)->not->toContain('es');
});

it('returns an empty array with a helpful note when no sites match a language', function () {
    $output = $this->registry->execute('get_sites', ['language' => 'xx-XX']);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['data'])->toBe([]);
    expect($payload['_notes'])->toContain('xx-XX');
});

it('mentions per-site language in the _notes hint', function () {
    createSite('es', 'es-ES', 'Spanish');

    $output = $this->registry->execute('get_sites', []);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['_notes'])->toContain('language');
});
