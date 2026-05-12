<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\Heading;
use craft\fieldlayoutelements\HorizontalRule;
use craft\fieldlayoutelements\LineBreak;
use craft\fieldlayoutelements\Markdown;
use craft\fieldlayoutelements\Template;
use craft\fieldlayoutelements\Tip;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\EntryType as EntryTypeBinder;
use markhuot\craftai\binders\Field as FieldBinder;
use markhuot\craftai\validators\ExistingEntryType;
use markhuot\craftai\validators\ExistingField;

/**
 * Insert or update a single element in an entry type's field layout. Elements
 * are identified by UID — pass `elementUid` to update an existing element, or
 * omit it and pass `type` to insert a new one. Element UIDs come back from
 * GetEntryTypes and from this tool's response.
 *
 * Supported element types: `customField` (wraps an existing custom field —
 * requires `field`), `title` (entry title field), `heading` (a section
 * heading — uses `headingText`), `tip` (a tip or warning callout — uses
 * `tipText` / `tipStyle` / `tipDismissible`), `markdown` (a markdown content
 * block — uses `markdownContent` / `markdownDisplayInPane`), `lineBreak` (a
 * visual line break), `horizontalRule` (an `<hr>`), `template` (renders a
 * custom template — uses `templatePath` / `templateMode`).
 *
 * Custom fields and the title field accept `label`, `instructions`, and
 * `required`. Custom fields, the title field, markdown blocks, and templates
 * accept `width` (25, 50, 75, or 100). Position the element with `position`
 * (`before`/`after` need `relativeTo`; `start`/`end` use `tab`, creating it
 * if it does not exist). Omitting `position` on update leaves the element in
 * place; on insert, the default is `end` of the first tab.
 *
 * The element type and underlying custom field cannot be changed on update —
 * remove the element with RemoveFieldLayoutElement and insert a new one
 * instead.
 */
class UpsertFieldLayoutElement extends Tool
{
    /**
     * Map of public type names to FQCNs. Public names are stable contract;
     * the FQCNs are an implementation detail.
     *
     * @var array<string, class-string>
     */
    private const TYPE_MAP = [
        'customField' => CustomField::class,
        'title' => EntryTitleField::class,
        'heading' => Heading::class,
        'tip' => Tip::class,
        'markdown' => Markdown::class,
        'lineBreak' => LineBreak::class,
        'horizontalRule' => HorizontalRule::class,
        'template' => Template::class,
    ];

    /**
     * @return array{_notes: string, data: array<array-key, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Entry type handle, UID, or ID whose field layout should be updated.')]
        #[Validate('required')]
        #[Validate(ExistingEntryType::class)]
        #[Bind(EntryTypeBinder::class)]
        EntryType|string|int|null $entryType,
        #[Description('UID of an existing element to update. Omit to insert a new element (and provide `type`). Element UIDs are returned by GetEntryTypes and by this tool.')]
        ?string $elementUid = null,
        #[Description('Element type — required when inserting. One of: "customField", "title", "heading", "tip", "markdown", "lineBreak", "horizontalRule", "template". Cannot be changed on update.')]
        #[Validate('required', whenMissing: 'elementUid')]
        #[Validate('in', range: ['customField', 'title', 'heading', 'tip', 'markdown', 'lineBreak', 'horizontalRule', 'template'])]
        ?string $type = null,
        #[Description('UID of the custom field to wrap. Required when inserting a "customField" element. Use GetFields to find the UID. Cannot be changed on update.')]
        #[Validate(ExistingField::class, uidOnly: true)]
        #[Bind(FieldBinder::class, uidOnly: true)]
        FieldInterface|string|null $field = null,
        #[Description('Label override. Applies to "customField" and "title" elements.')]
        ?string $label = null,
        #[Description('Instructions text shown beneath the field. Applies to "customField" and "title" elements.')]
        ?string $instructions = null,
        #[Description('Whether the field is required. Applies to "customField" and "title" elements.')]
        ?bool $required = null,
        #[Description('Layout width as a percentage. One of 25, 50, 75, or 100. Applies to "customField", "title", "markdown", and "template" elements.')]
        #[Validate('in', range: [25, 50, 75, 100])]
        ?int $width = null,
        #[Description('Heading text. Required content for "heading" elements.')]
        ?string $headingText = null,
        #[Description('Tip body text (Markdown supported). Required content for "tip" elements.')]
        ?string $tipText = null,
        #[Description('Tip style. One of "tip" (lightbulb) or "warning" (triangle). Defaults to "tip".')]
        #[Validate('in', range: ['tip', 'warning'])]
        ?string $tipStyle = null,
        #[Description('Whether the tip can be dismissed by the user.')]
        ?bool $tipDismissible = null,
        #[Description('Markdown body. Required content for "markdown" elements.')]
        ?string $markdownContent = null,
        #[Description('Whether to render the markdown inside a styled pane. Defaults to true.')]
        ?bool $markdownDisplayInPane = null,
        #[Description('Path to a Twig template file relative to your `templates/` folder. Required content for "template" elements.')]
        ?string $templatePath = null,
        #[Description('Template rendering context. One of "site" or "cp". Defaults to "site".')]
        #[Validate('in', range: ['site', 'cp'])]
        ?string $templateMode = null,
        #[Description('Where to place the element. "before"/"after" require relativeTo. "start"/"end" use the named tab (or the first tab if tab is omitted). Omit on update to leave the element in place; on insert the default is "end".')]
        #[Validate('in', range: ['before', 'after', 'start', 'end'])]
        ?string $position = null,
        #[Description('UID of an existing element to position relative to. Required when position is "before" or "after". Element UIDs are returned by GetEntryTypes and by this tool.')]
        ?string $relativeTo = null,
        #[Description('Tab name for the element. Only used with position "start" or "end". If the tab does not exist it is created at the end of the layout.')]
        ?string $tab = null,
    ): array|ToolOutput {
        assert($entryType instanceof EntryType);

        $isUpdate = $elementUid !== null;
        $layout = $entryType->getFieldLayout();

        $existingElement = null;
        $existingTab = null;
        $existingIndex = null;

        if ($isUpdate) {
            foreach ($layout->getTabs() as $candidateTab) {
                foreach ($candidateTab->getElements() as $i => $candidate) {
                    if (($candidate->uid ?? null) === $elementUid) {
                        $existingElement = $candidate;
                        $existingTab = $candidateTab;
                        $existingIndex = $i;
                        break 2;
                    }
                }
            }

            if ($existingElement === null) {
                return new ToolOutput(
                    "No element with UID \"{$elementUid}\" in the layout for entry type \"{$entryType->handle}\".",
                    isError: true,
                );
            }

            if ($type !== null && (self::TYPE_MAP[$type] ?? null) !== $existingElement::class) {
                return new ToolOutput(
                    'Cannot change an element\'s type on update; remove it and insert a new element instead.',
                    isError: true,
                );
            }

            if ($field instanceof FieldInterface
                && $existingElement instanceof CustomField
                && $existingElement->getField()->uid !== $field->uid
            ) {
                return new ToolOutput(
                    'Cannot change a customField element\'s underlying field on update; remove it and insert a new element instead.',
                    isError: true,
                );
            }

            $element = $existingElement;
        } else {
            assert($type !== null);

            if ($type === 'customField' && ! $field instanceof FieldInterface) {
                return new ToolOutput(
                    'field is required when inserting a "customField" element.',
                    isError: true,
                );
            }

            if ($type === 'customField') {
                assert($field instanceof FieldInterface);
                foreach ($layout->getTabs() as $existingTab2) {
                    foreach ($existingTab2->getElements() as $existing) {
                        if ($existing instanceof CustomField && $existing->getField()->id === $field->id) {
                            return new ToolOutput(
                                "Field \"{$field->handle}\" is already in the layout for entry type \"{$entryType->handle}\".",
                                isError: true,
                            );
                        }
                    }
                }
            }

            if ($type === 'title') {
                foreach ($layout->getTabs() as $existingTab2) {
                    foreach ($existingTab2->getElements() as $existing) {
                        if ($existing instanceof EntryTitleField) {
                            return new ToolOutput(
                                "Title field is already in the layout for entry type \"{$entryType->handle}\".",
                                isError: true,
                            );
                        }
                    }
                }
            }

            $element = $this->createElement($type, $field instanceof FieldInterface ? $field : null);
        }

        $this->applyProps(
            $element,
            label: $label,
            instructions: $instructions,
            required: $required,
            width: $width,
            headingText: $headingText,
            tipText: $tipText,
            tipStyle: $tipStyle,
            tipDismissible: $tipDismissible,
            markdownContent: $markdownContent,
            markdownDisplayInPane: $markdownDisplayInPane,
            templatePath: $templatePath,
            templateMode: $templateMode,
        );

        if ($isUpdate) {
            if ($position !== null) {
                assert($existingTab instanceof FieldLayoutTab);
                assert(is_int($existingIndex));

                $remaining = $existingTab->getElements();
                array_splice($remaining, $existingIndex, 1);
                $existingTab->setElements($remaining);

                $error = $this->placeElement($layout, $element, $position, $relativeTo, $tab);
                if ($error !== null) {
                    return $error;
                }
            }
        } else {
            $error = $this->placeElement($layout, $element, $position ?? 'end', $relativeTo, $tab);
            if ($error !== null) {
                return $error;
            }
        }

        if ($element instanceof EntryTitleField) {
            $entryType->hasTitleField = true;
        }

        if (! Craft::$app->entries->saveEntryType($entryType)) {
            $errors = $entryType->getErrorSummary(true);

            return new ToolOutput(
                'Could not save entry type: '.implode('; ', $errors),
                isError: true,
            );
        }

        $reloaded = Craft::$app->entries->getEntryTypeById($entryType->id);
        $target = $reloaded ?? $entryType;
        $elementUidStored = $element->uid ?? null;

        $notes = sprintf(
            '%s field-layout element%s in entry type "%s". Use get_entry_types to inspect the full layout, or remove_field_layout_element with this UID to drop it.',
            $isUpdate ? 'Updated' : 'Inserted',
            $elementUidStored !== null ? " uid={$elementUidStored}" : '',
            $target->handle,
        );

        return [
            '_notes' => $notes,
            'data' => self::summarizeLayout($target),
        ];
    }

    private function createElement(string $type, ?FieldInterface $field): \craft\base\FieldLayoutElement
    {
        $class = self::TYPE_MAP[$type];

        if ($type === 'customField') {
            assert($field instanceof FieldInterface);
            $element = new CustomField($field);
        } else {
            /** @var \craft\base\FieldLayoutElement $element */
            $element = new $class();
        }

        if (! isset($element->uid)) {
            $element->uid = StringHelper::UUID();
        }

        return $element;
    }

    private function applyProps(
        \craft\base\FieldLayoutElement $element,
        ?string $label,
        ?string $instructions,
        ?bool $required,
        ?int $width,
        ?string $headingText,
        ?string $tipText,
        ?string $tipStyle,
        ?bool $tipDismissible,
        ?string $markdownContent,
        ?bool $markdownDisplayInPane,
        ?string $templatePath,
        ?string $templateMode,
    ): void {
        if ($element instanceof BaseField) {
            if ($label !== null) {
                $element->label = $label;
            }
            if ($instructions !== null) {
                $element->instructions = $instructions;
            }
            if ($required !== null) {
                $element->required = $required;
            }
        }

        if ($width !== null && $element->hasCustomWidth()) {
            $element->width = $width;
        }

        if ($element instanceof Heading && $headingText !== null) {
            $element->heading = $headingText;
        }

        if ($element instanceof Tip) {
            if ($tipText !== null) {
                $element->tip = $tipText;
            }
            if ($tipStyle !== null) {
                $element->style = $tipStyle;
            }
            if ($tipDismissible !== null) {
                $element->dismissible = $tipDismissible;
            }
        }

        if ($element instanceof Markdown) {
            if ($markdownContent !== null) {
                $element->content = $markdownContent;
            }
            if ($markdownDisplayInPane !== null) {
                $element->displayInPane = $markdownDisplayInPane;
            }
        }

        if ($element instanceof Template) {
            if ($templatePath !== null) {
                $element->template = $templatePath;
            }
            if ($templateMode !== null) {
                $element->templateMode = $templateMode;
            }
        }
    }

    private function placeElement(
        FieldLayout $layout,
        \craft\base\FieldLayoutElement $element,
        string $position,
        ?string $relativeTo,
        ?string $tab,
    ): ?ToolOutput {
        if (in_array($position, ['before', 'after'], true)) {
            if ($relativeTo === null) {
                return new ToolOutput(
                    'relativeTo is required when position is "before" or "after".',
                    isError: true,
                );
            }

            foreach ($layout->getTabs() as $candidate) {
                foreach ($candidate->getElements() as $i => $existing) {
                    if (($existing->uid ?? null) === $relativeTo) {
                        $idx = $position === 'before' ? $i : $i + 1;
                        $elements = $candidate->getElements();
                        array_splice($elements, $idx, 0, [$element]);
                        $candidate->setElements($elements);

                        return null;
                    }
                }
            }

            return new ToolOutput(
                "relativeTo element \"{$relativeTo}\" is not present in the layout.",
                isError: true,
            );
        }

        $tabs = $layout->getTabs();
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

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function summarizeLayout(EntryType $entryType): array
    {
        $layout = $entryType->getFieldLayout();

        $tabs = array_map(static function (FieldLayoutTab $tab): array {
            return [
                'name' => $tab->name,
                'uid' => $tab->uid ?? null,
                'elements' => array_map(
                    static fn ($element): array => self::summarizeElement($element),
                    $tab->getElements(),
                ),
            ];
        }, $layout->getTabs());

        return [
            'entryTypeId' => $entryType->id,
            'entryTypeHandle' => $entryType->handle,
            'fieldLayoutId' => $layout->id,
            'tabs' => $tabs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summarizeElement(\craft\base\FieldLayoutElement $element): array
    {
        $row = [
            'uid' => $element->uid ?? null,
            'type' => self::publicTypeName($element::class),
            'class' => $element::class,
        ];

        if ($element instanceof BaseField) {
            $row['label'] = $element->label ?? null;
            $row['instructions'] = $element->instructions ?? null;
            $row['required'] = (bool) $element->required;
            $row['handle'] = $element->attribute();
        }

        if ($element instanceof CustomField) {
            try {
                $field = $element->getField();
                $row['fieldId'] = $field->id;
                $row['fieldUid'] = $field->uid;
                $row['fieldHandle'] = $field->handle;
                $row['fieldName'] = $field->name;
                $row['fieldType'] = $field::class;
            } catch (\Throwable) {
                // field has been deleted out from under the layout; leave fields off
            }
        }

        if ($element instanceof Heading) {
            $row['headingText'] = $element->heading;
        }

        if ($element instanceof Tip) {
            $row['tipText'] = $element->tip;
            $row['tipStyle'] = $element->style;
            $row['tipDismissible'] = $element->dismissible;
        }

        if ($element instanceof Markdown) {
            $row['markdownContent'] = $element->content;
            $row['markdownDisplayInPane'] = $element->displayInPane;
        }

        if ($element instanceof Template) {
            $row['templatePath'] = $element->template;
            $row['templateMode'] = $element->templateMode;
        }

        if ($element->hasCustomWidth()) {
            $row['width'] = $element->width;
        }

        return $row;
    }

    private static function publicTypeName(string $class): ?string
    {
        foreach (self::TYPE_MAP as $name => $cls) {
            if ($cls === $class) {
                return $name;
            }
        }

        return null;
    }
}
