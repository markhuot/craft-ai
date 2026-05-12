<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\Section;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;

/**
 * List all content sections defined in the CMS. Returns each section's ID,
 * name, handle, type (single, channel, or structure), and per-site settings.
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

        $data = array_values(array_map(
            static fn (Section $section): array => $section->toArray(),
            $sections,
        ));

        $notes = $data === []
            ? ($type !== null
                ? "No sections of type \"{$type}\" exist. Use upsert_section to create one."
                : 'No sections exist yet. Use upsert_section to create one.')
            : 'Returned '.count($data).' section(s). Use get_entry_types with a section handle to list its entry types, or get_entries with sectionId/section to fetch entries within a section.';

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
