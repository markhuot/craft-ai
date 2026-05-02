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
    /**
     * @return list<array<array-key, mixed>>
     */
    public function __invoke(
        #[Description('Filter by section type: "single", "channel", or "structure"')]
        #[Validate('in', range: ['single', 'channel', 'structure'])]
        ?string $type = null,
    ): array {
        $sections = $type !== null
            ? Craft::$app->entries->getSectionsByType($type)
            : Craft::$app->entries->getAllSections();

        return array_values(array_map(
            static fn (Section $section): array => $section->toArray(),
            $sections,
        ));
    }
}
