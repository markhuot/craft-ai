<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\EntryType;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Section as SectionBinder;
use markhuot\craftai\validators\ExistingSection;

/**
 * List entry types defined in the CMS. Returns each entry type's ID, name,
 * handle, and display options. Optionally filter by section.
 */
class GetEntryTypes extends Tool
{
    /**
     * @return list<array<array-key, mixed>>
     */
    public function __invoke(
        #[Description('Section handle or ID to filter entry types by')]
        #[Validate(ExistingSection::class)]
        #[Bind(SectionBinder::class)]
        \craft\models\Section|string|int|null $section = null,
    ): array {
        if ($section instanceof \craft\models\Section) {
            $entryTypes = $section->id !== null
                ? Craft::$app->entries->getEntryTypesBySectionId($section->id)
                : [];
        } else {
            $entryTypes = Craft::$app->entries->getAllEntryTypes();
        }

        return array_values(array_map(
            static function (EntryType $entryType): array {
                $row = $entryType->toArray();
                $layout = UpdateFieldLayout::summarizeLayout($entryType);
                $row['fieldLayoutId'] = $layout['fieldLayoutId'];
                $row['tabs'] = $layout['tabs'];

                return $row;
            },
            $entryTypes,
        ));
    }
}
