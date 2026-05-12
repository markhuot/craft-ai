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
 * Remove a single element from an entry type's field layout. Removes any
 * element regardless of kind — custom fields, the entry title field,
 * headings, tips, markdown blocks, line breaks, horizontal rules, and
 * templates are all supported. Identify the element by UID (returned by
 * GetEntryTypes and by UpsertFieldLayoutElement).
 *
 * Removing the last element from a tab leaves the tab in place (empty); use
 * UpsertEntryType or move elements with UpsertFieldLayoutElement if you want
 * to reorganize tabs. Removing the title field also flips the entry type's
 * `hasTitleField` flag to false to keep the layout and entry type
 * configuration consistent.
 */
class RemoveFieldLayoutElement extends Tool
{
    /**
     * @return array{_notes: string, data: array{removedElement: array<string, mixed>, layout: array<string, mixed>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Entry type handle, UID, or ID whose field layout the element belongs to.')]
        #[Validate('required')]
        #[Validate(ExistingEntryType::class)]
        #[Bind(EntryTypeBinder::class)]
        EntryType|string|int|null $entryType,
        #[Description('UID of the element to remove. Element UIDs are returned by GetEntryTypes and by UpsertFieldLayoutElement.')]
        #[Validate('required')]
        string $elementUid,
    ): array|ToolOutput {
        assert($entryType instanceof EntryType);

        $layout = $entryType->getFieldLayout();
        $removed = null;

        foreach ($layout->getTabs() as $tab) {
            $elements = $tab->getElements();
            foreach ($elements as $i => $element) {
                if (($element->uid ?? null) === $elementUid) {
                    $removed = $element;
                    array_splice($elements, $i, 1);
                    $tab->setElements($elements);
                    break 2;
                }
            }
        }

        if ($removed === null) {
            return new ToolOutput(
                "No element with UID \"{$elementUid}\" in the layout for entry type \"{$entryType->handle}\".",
                isError: true,
            );
        }

        $titleFieldFlipped = false;
        if ($removed instanceof EntryTitleField) {
            $entryType->hasTitleField = false;
            $titleFieldFlipped = true;
        }

        if (! Craft::$app->entries->saveEntryType($entryType)) {
            $errors = $entryType->getErrorSummary(true);

            return new ToolOutput(
                'Could not save entry type: '.implode('; ', $errors),
                isError: true,
            );
        }

        $reloaded = Craft::$app->entries->getEntryTypeById($entryType->id);
        $removedSummary = UpsertFieldLayoutElement::summarizeElement($removed);
        $layoutSummary = UpsertFieldLayoutElement::summarizeLayout($reloaded ?? $entryType);

        $elementType = is_string($removedSummary['type'] ?? null) ? $removedSummary['type'] : 'element';
        $titleNote = $titleFieldFlipped
            ? ' Because this was the title field, hasTitleField was also flipped to false — set titleFormat (or re-add the title field via upsert_field_layout_element) before saving entries.'
            : '';
        $customFieldNote = $elementType === 'customField'
            ? ' The underlying custom field still exists — call delete_fields to remove the field globally, or upsert_field_layout_element to re-attach it to another entry type.'
            : '';
        $notes = "Removed {$elementType} element {$elementUid} from entry type \"{$entryType->handle}\".{$titleNote}{$customFieldNote}";

        return [
            '_notes' => $notes,
            'data' => [
                'removedElement' => $removedSummary,
                'layout' => $layoutSummary,
            ],
        ];
    }
}
