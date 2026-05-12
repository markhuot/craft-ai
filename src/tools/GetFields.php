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
 *
 * For Matrix fields, the response enriches `settings.entryTypes` with each
 * block type's `handle`, `name`, and field layout (`tabs` containing the
 * sub-fields available inside each block). Use these block-type handles as
 * the `type` value when submitting blocks via `upsert_entry` / `upsert_draft`.
 */
class GetFields extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<array-key, mixed>>}
     */
    public function __invoke(
        #[Description('Fully-qualified field type class name to filter by (e.g. "craft\\fields\\PlainText"). Omit to return all fields.')]
        #[Validate(AvailableFieldType::class)]
        ?string $type = null,
    ): array {
        $fields = $type !== null
            ? Craft::$app->fields->getFieldsByType($type)
            : Craft::$app->fields->getAllFields();

        $data = array_values(array_map(
            static fn (FieldInterface $field): array => UpsertField::summarize($field),
            $fields,
        ));

        $notes = $data === []
            ? ($type !== null
                ? "No fields of type \"{$type}\" exist. Use upsert_field with that type to create one."
                : 'No global fields are defined. Use upsert_field to create one.')
            : 'Returned '.count($data).' field(s). Reference these handles in upsert_entry/upsert_draft, or call upsert_field with an id to modify a field. For Matrix fields, `settings.entryTypes[].handle` is the block type handle used when authoring blocks.';

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
