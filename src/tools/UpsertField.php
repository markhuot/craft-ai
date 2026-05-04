<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\base\FieldInterface;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Field as FieldBinder;
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
 */
class UpsertField extends Tool
{
    /**
     * @return array<array-key, mixed>|ToolOutput
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

        $field = Craft::$app->fields->createField($config);

        if (! Craft::$app->fields->saveField($field)) {
            $errors = $field->getErrorSummary(true);

            return new ToolOutput(
                'Could not save field: '.implode('; ', $errors),
                isError: true,
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
        ];
    }
}
