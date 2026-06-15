<?php

use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use markhuot\craftai\tools\DeleteSections;
use markhuot\craftai\tools\ToolRegistry;

beforeEach(function () {
    $this->registry = new ToolRegistry();
    $this->registry->register(DeleteSections::class);
});

/**
 * Create a section through the real Craft save path (project config), the way
 * craft-pest's Section::factory() did under Craft 5. The native
 * Section::factory()/seedSection() writes only DB rows and never registers the
 * section in project config, so DeleteSections — which removes the section via
 * the project-config delete path — would report success but never soft-delete
 * the row. Saving through the service keeps the delete genuinely effective.
 */
function makeDeletableSection(string $handle, string $name): Section
{
    $entryType = new EntryType([
        'name' => $name,
        'handle' => $handle.'Type',
    ]);
    $entryType->setFieldLayout(new FieldLayout(['type' => \craft\elements\Entry::class]));
    if (! Craft::$app->entries->saveEntryType($entryType)) {
        throw new \RuntimeException('Could not save entry type: '.implode('; ', $entryType->getErrorSummary(true)));
    }

    $section = new Section([
        'name' => $name,
        'handle' => $handle,
        'type' => 'channel',
        'enableVersioning' => true,
    ]);
    $section->setSiteSettings([
        new Section_SiteSettings([
            'siteId' => Craft::$app->sites->getPrimarySite()->id,
            'hasUrls' => false,
            'uriFormat' => null,
            'template' => null,
        ]),
    ]);
    $section->setEntryTypes([$entryType]);

    if (! Craft::$app->entries->saveSection($section)) {
        throw new \RuntimeException('Could not save section: '.implode('; ', $section->getErrorSummary(true)));
    }

    return $section;
}

it('deletes sections by ID', function () {
    $a = makeDeletableSection('a', 'A');
    $b = makeDeletableSection('b', 'B');

    $output = $this->registry->execute('delete_sections', ['ids' => [$a->id, $b->id]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results'][(string) $a->id]['deleted'])->toBeTrue();
    expect($payload['data']['results'][(string) $b->id]['deleted'])->toBeTrue();

    expect(Craft::$app->entries->getSectionById($a->id))->toBeNull();
    expect(Craft::$app->entries->getSectionById($b->id))->toBeNull();
});

it('reports unknown section IDs', function () {
    $output = $this->registry->execute('delete_sections', ['ids' => [999999]]);

    expect($output->isError)->toBeFalse($output->text);
    $payload = json_decode($output->text, true);
    expect($payload['data']['results']['999999']['deleted'])->toBeFalse();
    expect($payload['data']['results']['999999']['error'])->toContain('999999');
});

it('exposes a destructive annotation on the MCP descriptor', function () {
    $descriptor = $this->registry->describe('delete_sections');
    $mcp = $descriptor->toMcpTool();

    expect($mcp['annotations']['destructiveHint'])->toBeTrue();
    expect($mcp['annotations']['idempotentHint'])->toBeTrue();
});
