<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\Section;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;

/**
 * List all content sections defined in the CMS. Returns each section's ID,
 * name, handle, type (single, channel, or structure), per-site settings,
 * and `enabledSiteHandles` — the list of site handles the section is
 * actually available on. A site missing from that list means the section
 * has no site_settings row for it, so entries cannot be created or
 * translated into that site until upsert_section is re-run with the site
 * included in `sites`.
 */
class GetSections extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<array-key, mixed>>}
     */
    public function __invoke(
        #[Description('Filter by section type: "single", "channel", or "structure"')]
        #[Validate('in', range: ['single', 'channel', 'structure'])]
        ?string $type = null,
    ): array {
        $sections = $type !== null
            ? Craft::$app->entries->getSectionsByType($type)
            : Craft::$app->entries->getAllSections();

        $allSiteHandles = [];
        foreach (Craft::$app->sites->getAllSites() as $s) {
            if (is_string($s->handle) && $s->handle !== '') {
                $allSiteHandles[] = $s->handle;
            }
        }

        $data = array_values(array_map(
            fn (Section $section): array => $section->toArray()
                + ['enabledSiteHandles' => $this->enabledSiteHandles($section)],
            $sections,
        ));

        $notes = $this->buildNotes($data, $allSiteHandles, $type);

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }

    /**
     * @return list<string>
     */
    private function enabledSiteHandles(Section $section): array
    {
        $handles = [];
        foreach ($section->getSiteSettings() as $row) {
            if ($row->siteId === null) {
                continue;
            }
            $site = Craft::$app->sites->getSiteById((int) $row->siteId);
            if ($site !== null && is_string($site->handle) && $site->handle !== '') {
                $handles[] = $site->handle;
            }
        }

        return $handles;
    }

    /**
     * @param  list<array<array-key, mixed>>  $data
     * @param  list<string>  $allSiteHandles
     */
    private function buildNotes(array $data, array $allSiteHandles, ?string $type): string
    {
        if ($data === []) {
            return $type !== null
                ? "No sections of type \"{$type}\" exist. Use upsert_section to create one."
                : 'No sections exist yet. Use upsert_section to create one.';
        }

        $base = 'Returned '.count($data).' section(s). Use get_entry_types with a section handle to list its entry types, or get_entries with sectionId/section to fetch entries within a section.';

        if (count($allSiteHandles) <= 1) {
            return $base;
        }

        $sectionsMissingSites = [];
        foreach ($data as $row) {
            $rawEnabled = is_array($row['enabledSiteHandles'] ?? null) ? $row['enabledSiteHandles'] : [];
            $enabled = array_values(array_filter($rawEnabled, 'is_string'));
            $missing = array_values(array_diff($allSiteHandles, $enabled));
            if ($missing === []) {
                continue;
            }
            $handle = is_string($row['handle'] ?? null) ? $row['handle'] : '';
            $sectionsMissingSites[] = sprintf('"%s" missing [%s]', $handle, implode(', ', $missing));
        }

        if ($sectionsMissingSites !== []) {
            $base .= ' Translation readiness — sections not yet enabled on every site: '.implode('; ', $sectionsMissingSites).'. Re-call upsert_section with the full `sites` list to enable them.';
        }

        return $base;
    }
}
