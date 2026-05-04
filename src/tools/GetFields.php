<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\base\FieldInterface;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\validators\AvailableFieldType;

/**
 * List custom fields defined in the global field context. Returns each field's
 * ID, UID, name, handle, type (FQCN), translation settings, and type-specific
 * settings — the same shape returned by UpsertField. Optionally filter by
 * field type (FQCN, e.g. `craft\fields\PlainText`).
 */
class GetFields extends Tool
{
    /**
     * @return list<array<array-key, mixed>>
     */
    public function __invoke(
        #[Description('Fully-qualified field type class name to filter by (e.g. "craft\\fields\\PlainText"). Omit to return all fields.')]
        #[Validate(AvailableFieldType::class)]
        ?string $type = null,
    ): array {
        $fields = $type !== null
            ? Craft::$app->fields->getFieldsByType($type)
            : Craft::$app->fields->getAllFields();

        return array_values(array_map(
            static fn (FieldInterface $field): array => [
                'id' => $field->id,
                'uid' => $field->uid,
                'name' => $field->name,
                'handle' => $field->handle,
                'type' => $field::class,
                'instructions' => $field->instructions,
                'searchable' => $field->searchable,
                'translationMethod' => $field->translationMethod,
                'translationKeyFormat' => $field->translationKeyFormat,
                'settings' => $field->getSettings(),
            ],
            $fields,
        ));
    }
}
