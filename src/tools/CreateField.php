<?php

namespace markhuot\craftai\tools;

use Craft;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\validators\AvailableFieldType;

/**
 * Create a new custom field in the global field context. Pass `type` as the
 * fully-qualified class name of an installed field type (e.g.
 * `craft\fields\PlainText`, `craft\fields\Assets`). The accepted set is
 * resolved at runtime, so plugin-registered field types are accepted
 * automatically — call `GetHealth` or inspect the schema to discover what's
 * installed.
 *
 * To attach the field to an entry type, call UpsertEntryType (or edit the
 * entry type's field layout) after creating it.
 */
class CreateField extends Tool
{
    /**
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Display name for the field (e.g. "Hero Image").')]
        #[Validate('required')]
        #[Validate('string', max: 255)]
        string $name,
        #[Description('Field handle used in templates and queries (e.g. "heroImage"). Must be unique within the global field context.')]
        #[Validate('required')]
        #[Validate('string', max: 255)]
        string $handle,
        #[Description('Fully-qualified class name of an installed field type (e.g. "craft\\fields\\PlainText", "craft\\fields\\Assets", "craft\\fields\\Matrix"). Plugin-registered field types are accepted automatically.')]
        #[Validate('required')]
        #[Validate(AvailableFieldType::class)]
        string $type,
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
        $config = [
            'type' => $type,
            'name' => $name,
            'handle' => $handle,
        ];

        if ($instructions !== null) {
            $config['instructions'] = $instructions;
        }

        if ($searchable !== null) {
            $config['searchable'] = $searchable;
        }

        if ($translationMethod !== null) {
            $config['translationMethod'] = $translationMethod;
        }

        if ($translationKeyFormat !== null) {
            $config['translationKeyFormat'] = $translationKeyFormat;
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
            'type' => $type,
            'instructions' => $field->instructions,
            'searchable' => $field->searchable,
            'translationMethod' => $field->translationMethod,
            'translationKeyFormat' => $field->translationKeyFormat,
        ];
    }
}
