<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayoutTab;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\EntryType as EntryTypeBinder;
use markhuot\craftai\binders\Field as FieldBinder;
use markhuot\craftai\validators\ExistingEntryType;
use markhuot\craftai\validators\ExistingField;

/**
 * Add an existing custom field to an entry type's field layout. Position the
 * field relative to an existing one (`before` / `after` with `relativeTo`) or
 * at the `start` / `end` of a tab. When `tab` is omitted, the first tab is
 * used; when `tab` names a tab that doesn't exist, a new tab is appended.
 *
 * Use CreateField first to make a field, then call this tool to attach it.
 */
class UpdateFieldLayout extends Tool
{
    /**
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Entry type handle or ID whose field layout should be updated.')]
        #[Validate('required')]
        #[Validate(ExistingEntryType::class)]
        #[Bind(EntryTypeBinder::class)]
        EntryType|string|int|null $entryType,
        #[Description('Handle, UID, or ID of the field to add to the layout.')]
        #[Validate('required')]
        #[Validate(ExistingField::class)]
        #[Bind(FieldBinder::class)]
        FieldInterface|string|int|null $field,
        #[Description('Where to place the field. "before"/"after" require relativeTo. "start"/"end" use the named tab (or the first tab if tab is omitted).')]
        #[Validate('in', range: ['before', 'after', 'start', 'end'])]
        string $position = 'end',
        #[Description('Handle, UID, or ID of an existing field in the layout to position relative to. Required when position is "before" or "after".')]
        #[Validate(ExistingField::class)]
        #[Bind(FieldBinder::class)]
        FieldInterface|string|int|null $relativeTo = null,
        #[Description('Tab name to insert into. Only used for position "start"/"end". If the tab does not exist, it is created at the end of the layout.')]
        ?string $tab = null,
    ): array|ToolOutput {
        assert($entryType instanceof EntryType);
        assert($field instanceof FieldInterface);

        if (in_array($position, ['before', 'after'], true) && ! $relativeTo instanceof FieldInterface) {
            return new ToolOutput(
                'relativeTo is required when position is "before" or "after".',
                isError: true,
            );
        }

        $layout = $entryType->getFieldLayout();

        foreach ($layout->getTabs() as $existingTab) {
            foreach ($existingTab->getElements() as $element) {
                if ($element instanceof CustomField && $element->getField()->id === $field->id) {
                    return new ToolOutput(
                        "Field \"{$field->handle}\" is already in the layout for entry type \"{$entryType->handle}\".",
                        isError: true,
                    );
                }
            }
        }

        $element = new CustomField($field);

        $tabs = $layout->getTabs();

        if (in_array($position, ['before', 'after'], true)) {
            assert($relativeTo instanceof FieldInterface);

            $targetTab = null;
            $targetIndex = null;

            foreach ($tabs as $candidate) {
                foreach ($candidate->getElements() as $i => $existing) {
                    if ($existing instanceof CustomField && $existing->getField()->id === $relativeTo->id) {
                        $targetTab = $candidate;
                        $targetIndex = $position === 'before' ? $i : $i + 1;
                        break 2;
                    }
                }
            }

            if ($targetTab === null) {
                return new ToolOutput(
                    "relativeTo field \"{$relativeTo->handle}\" is not present in the layout for entry type \"{$entryType->handle}\".",
                    isError: true,
                );
            }

            $elements = $targetTab->getElements();
            array_splice($elements, $targetIndex, 0, [$element]);
            $targetTab->setElements($elements);
        } else {
            $targetTab = null;

            if ($tab !== null) {
                foreach ($tabs as $candidate) {
                    if ($candidate->name === $tab) {
                        $targetTab = $candidate;
                        break;
                    }
                }

                if ($targetTab === null) {
                    $targetTab = new FieldLayoutTab([
                        'layout' => $layout,
                        'name' => $tab,
                        'sortOrder' => count($tabs) + 1,
                        'elements' => [],
                    ]);
                    $tabs[] = $targetTab;
                    $layout->setTabs($tabs);
                }
            } else {
                $targetTab = $tabs[0] ?? null;

                if ($targetTab === null) {
                    $targetTab = new FieldLayoutTab([
                        'layout' => $layout,
                        'name' => 'Content',
                        'sortOrder' => 1,
                        'elements' => [],
                    ]);
                    $layout->setTabs([$targetTab]);
                }
            }

            $elements = $targetTab->getElements();

            if ($position === 'start') {
                array_unshift($elements, $element);
            } else {
                $elements[] = $element;
            }

            $targetTab->setElements($elements);
        }

        if (! Craft::$app->entries->saveEntryType($entryType)) {
            $errors = $entryType->getErrorSummary(true);

            return new ToolOutput(
                'Could not save entry type: '.implode('; ', $errors),
                isError: true,
            );
        }

        return self::summarizeLayout($entryType);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function summarizeLayout(EntryType $entryType): array
    {
        $layout = $entryType->getFieldLayout();

        $tabs = array_map(static function (FieldLayoutTab $tab): array {
            $elements = array_map(static function ($element): array {
                $row = [
                    'type' => $element::class,
                    'uid' => $element->uid ?? null,
                ];

                if ($element instanceof CustomField) {
                    $field = $element->getField();
                    $row['fieldId'] = $field->id;
                    $row['fieldHandle'] = $field->handle;
                    $row['fieldName'] = $field->name;
                    $row['fieldType'] = $field::class;
                }

                if ($element instanceof BaseField) {
                    $row['handle'] = $element->attribute();
                    $row['required'] = (bool) $element->required;
                }

                return $row;
            }, $tab->getElements());

            return [
                'name' => $tab->name,
                'uid' => $tab->uid ?? null,
                'elements' => $elements,
            ];
        }, $layout->getTabs());

        return [
            'entryTypeId' => $entryType->id,
            'entryTypeHandle' => $entryType->handle,
            'fieldLayoutId' => $layout->id,
            'tabs' => $tabs,
        ];
    }
}
