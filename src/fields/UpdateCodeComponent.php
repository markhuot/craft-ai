<?php

namespace markhuot\craftai\fields;

use Craft;
use craft\base\Field;
use craft\elements\Entry;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\tools\Tool;
use markhuot\craftai\tools\ToolKind;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\validators\ExistingDraft;
use markhuot\craftai\validators\ExistingEntry;

/**
 * Write one or more of the `twig`, `css`, and `js` tabs of a Code
 * Component field on a specific entry (or draft). Pass `entryId` for a
 * canonical entry or `draftId` for a draft — exactly one of them. The
 * `fieldHandle` must reference a CodeComponent field on that element's
 * field layout; other field types are rejected so this tool cannot be
 * misused to overwrite arbitrary content fields.
 *
 * Tabs not supplied are left unchanged. Per-tab Craft permissions apply:
 * the agent can only write tabs the current user is allowed to edit; an
 * attempt to write a forbidden tab returns an error without partial
 * mutation. There is no per-edit accept/reject flow — Craft's native
 * draft workflow is the rollback mechanism, so prefer writing to a
 * draft when iterating.
 */
class UpdateCodeComponent extends Tool
{
    public const KIND = ToolKind::DraftWrite;

    /**
     * Restricted to the CodeComponent field's Prompt tab — the only
     * surface where this tool makes sense. The general CP chat at
     * `/ai/sessions`, the front-end widget, and external MCP clients all
     * get filtered out of the registry before the LLM sees its tool list.
     */
    public const ALLOWED_CLIENTS = [ClientType::CODE_COMPONENT_FIELD];

    /**
     * @return array{_notes: string, data: array<string, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Canonical entry id whose CodeComponent field should be updated. Provide this OR `draftId`, not both.')]
        #[Validate('required', whenMissing: 'draftId')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entryId = null,
        #[Description('Draft id whose CodeComponent field should be updated. Provide this OR `entryId`, not both.')]
        #[Validate('required', whenMissing: 'entryId')]
        #[Validate(ExistingDraft::class)]
        #[Bind(DraftBinder::class)]
        Entry|int|null $draftId = null,
        #[Description('Handle of the CodeComponent field on the resolved element to update.')]
        #[Validate('required')]
        #[Validate('string')]
        ?string $fieldHandle = null,
        #[Description('New value for the Twig tab. Omit to leave the existing value untouched.')]
        ?string $twig = null,
        #[Description('New value for the CSS tab. Omit to leave the existing value untouched.')]
        ?string $css = null,
        #[Description('New value for the JS tab. Omit to leave the existing value untouched.')]
        ?string $js = null,
    ): array|ToolOutput {
        $element = $entryId instanceof Entry ? $entryId : ($draftId instanceof Entry ? $draftId : null);
        if (! $element instanceof Entry) {
            return new ToolOutput('Could not resolve the target entry or draft.', isError: true);
        }

        if ($fieldHandle === null || $fieldHandle === '') {
            return new ToolOutput('fieldHandle is required.', isError: true);
        }

        $layout = $element->getFieldLayout();
        $field = $layout?->getFieldByHandle($fieldHandle);
        if (! $field instanceof Field) {
            return new ToolOutput("No field \"{$fieldHandle}\" found on this entry's field layout.", isError: true);
        }

        if (! $field instanceof CodeComponent) {
            $type = $field::class;

            return new ToolOutput(
                "Field \"{$fieldHandle}\" is a {$type}, not a CodeComponent. This tool only updates CodeComponent fields.",
                isError: true,
            );
        }

        $touchedTabs = array_filter(
            ['twig' => $twig, 'css' => $css, 'js' => $js],
            static fn (?string $value): bool => $value !== null,
        );

        if ($touchedTabs === []) {
            return new ToolOutput('Provide at least one of `twig`, `css`, or `js` to update.', isError: true);
        }

        // Permission gate per touched tab. We collect *all* denials so the
        // agent sees the full list in one shot rather than discovering them
        // one round-trip at a time.
        $permissions = CodeComponentPermissions::resolve(Craft::$app->getUser()->getIdentity());
        $denied = [];
        foreach (array_keys($touchedTabs) as $tab) {
            if (empty($permissions[$tab])) {
                $denied[] = $tab;
            }
        }
        if ($denied !== []) {
            return new ToolOutput(
                'You do not have permission to update the following tab(s): '.implode(', ', $denied).'.',
                isError: true,
            );
        }

        $current = $element->getFieldValue($fieldHandle);
        if (! $current instanceof CodeComponentValue) {
            $current = new CodeComponentValue();
            $current->element = $element;
        }

        if (array_key_exists('twig', $touchedTabs)) {
            $current->twig = (string) $touchedTabs['twig'];
        }
        if (array_key_exists('css', $touchedTabs)) {
            $current->css = (string) $touchedTabs['css'];
        }
        if (array_key_exists('js', $touchedTabs)) {
            $current->js = (string) $touchedTabs['js'];
        }

        $element->setFieldValue($fieldHandle, $current);

        if (! Craft::$app->getElements()->saveElement($element)) {
            $errors = $element->getErrorSummary(true);

            return new ToolOutput(
                'Could not save the element: '.implode('; ', $errors),
                isError: true,
            );
        }

        $isDraft = $element->getIsDraft();
        $idLabel = $isDraft
            ? "draftId={$element->draftId}"
            : "entryId={$element->id}";

        return [
            '_notes' => sprintf(
                'Updated CodeComponent field "%s" on %s (%s tab%s changed).',
                $fieldHandle,
                $idLabel,
                implode('+', array_keys($touchedTabs)),
                count($touchedTabs) === 1 ? '' : 's',
            ),
            'data' => [
                'fieldHandle' => $fieldHandle,
                'entryId' => $isDraft ? null : (int) $element->id,
                'draftId' => $isDraft ? (int) $element->draftId : null,
                'twig' => $current->twig,
                'css' => $current->css,
                'js' => $current->js,
                'touchedTabs' => array_keys($touchedTabs),
            ],
        ];
    }
}
