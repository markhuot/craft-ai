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
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<array-key, mixed>>}
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

        $data = array_values(array_map(
            static function (EntryType $entryType): array {
                $row = $entryType->toArray();
                $layout = UpsertFieldLayoutElement::summarizeLayout($entryType);
                $row['fieldLayoutId'] = $layout['fieldLayoutId'];
                $row['tabs'] = $layout['tabs'];

                return $row;
            },
            $entryTypes,
        ));

        $scope = $section instanceof \craft\models\Section
            ? " in section \"{$section->handle}\""
            : '';
        $notes = $data === []
            ? "No entry types found{$scope}. Use upsert_entry_type to define one."
            : 'Returned '.count($data)." entry type(s){$scope}. Each row includes `tabs` describing the field layout — use those field handles when creating entries with upsert_entry. Call upsert_entry_type with an id to modify a type.";

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
