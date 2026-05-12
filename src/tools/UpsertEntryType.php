<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\models\EntryType;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\EntryType as EntryTypeBinder;
use markhuot\craftai\validators\ExistingEntryType;

/**
 * Create or update an entry type in the CMS. Pass `id` to update an existing
 * entry type; omit it to create a new one (in which case `name` and `handle`
 * are required). Returns the saved entry type's full details on success.
 *
 * Newly created entry types are not automatically attached to any section —
 * use UpsertSection to assign them.
 */
class UpsertEntryType extends Tool
{
    /**
     * @return array{_notes: string, data: array<array-key, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Existing entry type ID or handle to update. Omit to create a new entry type.')]
        #[Validate(ExistingEntryType::class)]
        #[Bind(EntryTypeBinder::class)]
        EntryType|string|int|null $id = null,
        #[Description('Display name for the entry type (e.g. "Article"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $name = null,
        #[Description('Entry type handle (e.g. "article"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $handle = null,
        #[Description('Optional description shown in the control panel')]
        ?string $description = null,
        #[Description('Icon name for the entry type (e.g. "newspaper")')]
        ?string $icon = null,
        #[Description('Color label: "red", "orange", "amber", "yellow", "lime", "green", "emerald", "teal", "cyan", "sky", "blue", "indigo", "violet", "purple", "fuchsia", "pink", "rose", or "gray"')]
        ?string $color = null,
        #[Description('Whether entries of this type have a Title field (defaults to true on create; leaves existing value untouched on update)')]
        ?bool $hasTitleField = null,
        #[Description('Title format used to auto-generate titles when hasTitleField is false (e.g. "{myField}")')]
        ?string $titleFormat = null,
        #[Description('Whether to show the Slug field in the editor (defaults to true on create)')]
        ?bool $showSlugField = null,
        #[Description('Whether to show the Status field in the editor (defaults to true on create)')]
        ?bool $showStatusField = null,
    ): array|ToolOutput {
        $isUpdate = $id instanceof EntryType;

        if ($isUpdate) {
            $entryType = $id;
        } else {
            assert($name !== null);
            assert($handle !== null);

            $entryType = new EntryType();
        }

        if ($name !== null) {
            $entryType->name = $name;
        }

        if ($handle !== null) {
            $entryType->handle = $handle;
        }

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

        if ($hasTitleField !== null) {
            $entryType->hasTitleField = $hasTitleField;
        } elseif (! $isUpdate) {
            $entryType->hasTitleField = true;
        }

        if ($showSlugField !== null) {
            $entryType->showSlugField = $showSlugField;
        } elseif (! $isUpdate) {
            $entryType->showSlugField = true;
        }

        if ($showStatusField !== null) {
            $entryType->showStatusField = $showStatusField;
        } elseif (! $isUpdate) {
            $entryType->showStatusField = true;
        }

        if ($hasTitleField !== null || ! $isUpdate) {
            $fieldLayout = $entryType->getFieldLayout();
            if ($entryType->hasTitleField) {
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
        }

        if (! Craft::$app->entries->saveEntryType($entryType)) {
            $errors = $entryType->getErrorSummary(true);

            return new ToolOutput(
                'Could not save entry type: '.implode('; ', $errors),
                isError: true,
            );
        }

        $notes = sprintf(
            '%s entry type id=%d (handle="%s"). Attach it to a section via upsert_section, and edit its field layout with upsert_field_layout_element.',
            $isUpdate ? 'Updated' : 'Created',
            $entryType->id,
            $entryType->handle,
        );

        return [
            '_notes' => $notes,
            'data' => $entryType->toArray(),
        ];
    }
}
