<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\base\FieldInterface;
use craft\fields\Assets as AssetsField;
use craft\fields\Matrix as MatrixField;
use craft\models\EntryType;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Field as FieldBinder;
use markhuot\craftai\validators\AssetSettingsValidation;
use markhuot\craftai\validators\AvailableFieldType;
use markhuot\craftai\validators\ExistingField;

/**
 * Create or update a custom field in the global field context. Pass `id` to
 * update an existing field; omit it to create a new one (in which case `name`,
 * `handle`, and `type` are required). Pass `type` as the fully-qualified class
 * name of an installed field type (e.g. `craft\fields\PlainText`,
 * `craft\fields\Assets`). The accepted set is resolved at runtime, so
 * plugin-registered field types are accepted automatically — call `GetHealth`
 * or inspect the schema to discover what's installed.
 *
 * To attach the field to an entry type, call UpsertEntryType (or edit the
 * entry type's field layout) after creating it.
 *
 * Field-type-specific options (character limits, source UIDs, option lists,
 * sub-fields, etc.) are passed via `settings` as an associative array. The
 * accepted keys depend on the field type — inspect an existing field of the
 * same type to discover what's available. When `saveField` fails validation
 * (for either core attributes or settings), the per-attribute error messages
 * are returned so the caller can correct the input and retry.
 *
 * Assets fields require an upload location to be set in `settings`, otherwise
 * the field renders with an "invalid volume" error in the control panel. Pass
 * `defaultUploadLocationSource` (when `restrictLocation` is false/absent) or
 * `restrictedLocationSource` (when `restrictLocation` is true) as a volume
 * source key in the form `volume:<uid>` — call `get_volumes` to discover UIDs.
 *
 * Matrix fields use entry types as their block types. The response enriches
 * `settings.entryTypes` with each block type's `handle`, `name`, and field
 * layout (tabs and sub-fields) so callers can see what blocks are available
 * and which sub-fields each block accepts. To change which entry types are
 * usable as blocks, pass `settings.entryTypes` as a list of `{uid: ...}`
 * objects (call `get_entry_types` to discover available UIDs and handles).
 * To change an existing block type's sub-fields, edit the entry type's field
 * layout via `upsert_field_layout_element` — Matrix blocks share entry types
 * with regular sections.
 */
class UpsertField extends Tool
{
    /**
     * @param  array<string, mixed>|null  $settings  Field-type-specific settings keyed by setting name
     * @return array{_notes: string, data: array<array-key, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Existing field ID, handle, or UID to update. Omit to create a new field.')]
        #[Validate(ExistingField::class)]
        #[Bind(FieldBinder::class)]
        FieldInterface|string|int|null $id = null,
        #[Description('Display name for the field (e.g. "Hero Image"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $name = null,
        #[Description('Field handle used in templates and queries (e.g. "heroImage"). Must be unique within the global field context. Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $handle = null,
        #[Description('Fully-qualified class name of an installed field type (e.g. "craft\\fields\\PlainText", "craft\\fields\\Assets", "craft\\fields\\Matrix"). Required when creating. Changing this on an existing field will convert its type.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate(AvailableFieldType::class)]
        ?string $type = null,
        #[Description('Optional instructions shown beneath the field in the control panel.')]
        ?string $instructions = null,
        #[Description('Whether the field\'s value is included in element search indexes.')]
        ?bool $searchable = null,
        #[Description('Translation method: "none", "site", "siteGroup", "language", or "custom".')]
        #[Validate('in', range: ['none', 'site', 'siteGroup', 'language', 'custom'])]
        ?string $translationMethod = null,
        #[Description('Translation key format used when translationMethod is "custom" (e.g. "{section.handle}").')]
        ?string $translationKeyFormat = null,
        #[Description('Field-type-specific settings as an associative object (e.g. {"charLimit": 255, "multiline": true} for PlainText). On update, provided keys are merged into the existing settings; omitted keys are preserved. Inspect a field of the same type to discover accepted keys.')]
        #[Validate(AssetSettingsValidation::class, whenIsA: ['type' => AssetsField::class, 'id' => AssetsField::class])]
        ?array $settings = null,
    ): array|ToolOutput {
        $isUpdate = $id instanceof FieldInterface;

        $config = [
            'type' => $type ?? ($isUpdate ? $id::class : null),
            'name' => $name ?? ($isUpdate ? $id->name : null),
            'handle' => $handle ?? ($isUpdate ? $id->handle : null),
        ];

        if ($isUpdate) {
            $config['id'] = $id->id;
            $config['uid'] = $id->uid;
        }

        if ($instructions !== null) {
            $config['instructions'] = $instructions;
        } elseif ($isUpdate) {
            $config['instructions'] = $id->instructions;
        }

        if ($searchable !== null) {
            $config['searchable'] = $searchable;
        } elseif ($isUpdate) {
            $config['searchable'] = $id->searchable;
        }

        if ($translationMethod !== null) {
            $config['translationMethod'] = $translationMethod;
        } elseif ($isUpdate) {
            $config['translationMethod'] = $id->translationMethod;
        }

        if ($translationKeyFormat !== null) {
            $config['translationKeyFormat'] = $translationKeyFormat;
        } elseif ($isUpdate) {
            $config['translationKeyFormat'] = $id->translationKeyFormat;
        }

        $existingSettings = $isUpdate ? $id->getSettings() : [];
        $mergedSettings = array_merge($existingSettings, $settings ?? []);
        if ($mergedSettings !== []) {
            $config['settings'] = $mergedSettings;
        }

        $field = Craft::$app->fields->createField($config);

        if (! Craft::$app->fields->saveField($field)) {
            $errors = $field->getErrors();
            $summary = [];
            foreach ($errors as $attribute => $messages) {
                foreach ($messages as $message) {
                    $summary[] = $attribute.': '.$message;
                }
            }

            return new ToolOutput(
                'Could not save field: '.implode('; ', $summary),
                isError: true,
            );
        }

        $notes = sprintf(
            '%s field id=%d (handle="%s"). Attach it to an entry type\'s field layout via upsert_field_layout_element (pass field=%s as the field UID).',
            $isUpdate ? 'Updated' : 'Created',
            $field->id,
            $field->handle,
            $field->uid,
        );

        return [
            '_notes' => $notes,
            'data' => self::summarize($field),
        ];
    }

    /**
     * Build the public payload for a field — the same shape returned by both
     * `UpsertField` and `GetFields`. For Matrix fields, `settings.entryTypes`
     * is enriched with each block type's handle, name, and field layout.
     *
     * @return array<array-key, mixed>
     */
    public static function summarize(FieldInterface $field): array
    {
        $settings = $field->getSettings();

        if ($field instanceof MatrixField) {
            $settings['entryTypes'] = array_map(
                static fn (EntryType $entryType): array => self::summarizeBlockType($entryType),
                $field->getEntryTypes(),
            );
        }

        return [
            'id' => $field->id,
            'uid' => $field->uid,
            'name' => $field->name,
            'handle' => $field->handle,
            'type' => $field::class,
            'instructions' => $field->instructions,
            'searchable' => $field->searchable,
            'translationMethod' => $field->translationMethod,
            'translationKeyFormat' => $field->translationKeyFormat,
            'settings' => $settings,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function summarizeBlockType(EntryType $entryType): array
    {
        $layout = UpsertFieldLayoutElement::summarizeLayout($entryType);

        return [
            'uid' => $entryType->uid,
            'id' => $entryType->id,
            'handle' => $entryType->handle,
            'name' => $entryType->name,
            'fieldLayoutId' => $layout['fieldLayoutId'],
            'tabs' => $layout['tabs'],
        ];
    }
}
