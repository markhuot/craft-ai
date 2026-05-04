<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;

/**
 * Create a new entry type in the CMS. Returns the created entry type's full
 * details on success, or an error describing why it could not be saved.
 *
 * The new entry type is not automatically attached to any section — use
 * CreateSection or update an existing section to assign it.
 */
class CreateEntryType extends Tool
{
    /**
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Display name for the entry type (e.g. "Article")')]
        #[Validate('string', max: 255)]
        string $name,
        #[Description('Entry type handle (e.g. "article")')]
        #[Validate('string', max: 255)]
        string $handle,
        #[Description('Optional description shown in the control panel')]
        ?string $description = null,
        #[Description('Icon name for the entry type (e.g. "newspaper")')]
        ?string $icon = null,
        #[Description('Color label: "red", "orange", "amber", "yellow", "lime", "green", "emerald", "teal", "cyan", "sky", "blue", "indigo", "violet", "purple", "fuchsia", "pink", "rose", or "gray"')]
        ?string $color = null,
        #[Description('Whether entries of this type have a Title field (default true)')]
        bool $hasTitleField = true,
        #[Description('Title format used to auto-generate titles when hasTitleField is false (e.g. "{myField}")')]
        ?string $titleFormat = null,
        #[Description('Whether to show the Slug field in the editor (default true)')]
        bool $showSlugField = true,
        #[Description('Whether to show the Status field in the editor (default true)')]
        bool $showStatusField = true,
    ): array|ToolOutput {
        $entryType = new EntryType([
            'name' => $name,
            'handle' => $handle,
            'hasTitleField' => $hasTitleField,
            'showSlugField' => $showSlugField,
            'showStatusField' => $showStatusField,
        ]);

        if ($description !== null) {
            $entryType->description = $description;
        }

        if ($icon !== null) {
            $entryType->icon = $icon;
        }

        if ($color !== null) {
            $entryType->color = \craft\enums\Color::from($color);
        }

        if ($titleFormat !== null) {
            $entryType->titleFormat = $titleFormat;
        }

        $fieldLayout = $entryType->getFieldLayout();
        if ($hasTitleField) {
            if (! $fieldLayout->isFieldIncluded('title')) {
                $fieldLayout->prependElements([new EntryTitleField()]);
            }
        } else {
            foreach ($fieldLayout->getTabs() as $tab) {
                $tab->setElements(array_filter(
                    $tab->getElements(),
                    fn ($element) => ! $element instanceof EntryTitleField,
                ));
            }
        }

        if (! Craft::$app->entries->saveEntryType($entryType)) {
            $errors = $entryType->getErrorSummary(true);

            return new ToolOutput(
                'Could not save entry type: '.implode('; ', $errors),
                isError: true,
            );
        }

        return $entryType->toArray();
    }
}
