<?php

use craft\models\Site;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertSite;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(UpsertSite::class);
    $this->preexistingSiteIds = array_map(
        fn ($s) => $s->id,
        Craft::$app->sites->getAllSites(true),
    );
});

afterEach(function () {
    // upsert_site writes to project config (file-backed), which isn't
    // rolled back by the test DB transaction. Drop any site this test
    // created so the next file isn't poisoned by leftover rows.
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

it('creates a new site with the required minimum fields', function () {
    $output = $this->registry->execute('upsert_site', [
        'name' => 'Spanish (Spain)',
        'handle' => 'esES',
        'language' => 'es-ES',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);

    expect($payload['data']['handle'])->toBe('esES');
    expect($payload['data']['language'])->toBe('es-ES');
    expect($payload['data']['name'])->toBe('Spanish (Spain)');

    $created = Craft::$app->sites->getSiteByHandle('esES');
    expect($created)->not->toBeNull();
    expect($created->language)->toBe('es-ES');
});

it('updates an existing site without requiring name/handle/language', function () {
    $group = Craft::$app->sites->getAllGroups()[0];
    $site = new Site([
        'groupId' => $group->id,
        'handle' => 'frFR',
        'name' => 'French',
        'language' => 'fr-FR',
        'primary' => false,
        'hasUrls' => false,
    ]);
    Craft::$app->sites->saveSite($site);

    $output = $this->registry->execute('upsert_site', [
        'id' => (string) $site->id,
        'name' => 'French (France)',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $reloaded = Craft::$app->sites->getSiteById($site->id);
    expect($reloaded->getName())->toBe('French (France)');
    expect($reloaded->language)->toBe('fr-FR');
});

it('resolves an existing site by handle for update', function () {
    $group = Craft::$app->sites->getAllGroups()[0];
    $site = new Site([
        'groupId' => $group->id,
        'handle' => 'jaJP',
        'name' => 'Japanese',
        'language' => 'ja',
        'primary' => false,
        'hasUrls' => false,
    ]);
    Craft::$app->sites->saveSite($site);

    $output = $this->registry->execute('upsert_site', [
        'id' => 'jaJP',
        'language' => 'ja-JP',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $reloaded = Craft::$app->sites->getSiteByHandle('jaJP');
    expect($reloaded->language)->toBe('ja-JP');
});

it('requires name, handle, and language when creating', function () {
    $output = $this->registry->execute('upsert_site', [
        'name' => 'Incomplete',
    ]);

    expect($output->isError)->toBeTrue();
});

it('rejects an unknown id', function () {
    $output = $this->registry->execute('upsert_site', [
        'id' => '99999999',
    ]);

    expect($output->isError)->toBeTrue();
});

it('rejects an unknown group on create', function () {
    $output = $this->registry->execute('upsert_site', [
        'name' => 'German',
        'handle' => 'deDE',
        'language' => 'de-DE',
        'group' => 'no-such-group',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('No site group found');
});

it('mentions translation workflow in the _notes hint', function () {
    $output = $this->registry->execute('upsert_site', [
        'name' => 'Italian',
        'handle' => 'itIT',
        'language' => 'it-IT',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = decode($output);
    expect($payload['_notes'])->toContain('upsert_entry');
    expect($payload['_notes'])->toContain('itIT');
});

it('warns when existing sections do not enable the newly created site', function () {
    $h = 'warnsite'.bin2hex(random_bytes(3));
    \markhuot\craftpest\factories\Section::factory()
        ->name(ucfirst($h))->handle($h)->type('channel')
        ->create();

    $output = $this->registry->execute('upsert_site', [
        'name' => 'German',
        'handle' => 'deDE',
        'language' => 'de-DE',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    expect($output->text)->toContain($h);
    expect($output->text)->toContain('upsert_section');
});
