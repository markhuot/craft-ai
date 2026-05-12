<?php

namespace markhuot\craftai\tools;

use Craft;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;

/**
 * Delete one or more custom fields by ID. Deleting a field removes its values
 * from all elements that use it and detaches it from any field layouts.
 * Returns a per-ID result map.
 */
#[ToolAttribute(annotations: [
    'title' => 'Delete fields',
    'destructiveHint' => true,
    'idempotentHint' => true,
    'readOnlyHint' => false,
    'openWorldHint' => false,
])]
class DeleteFields extends Tool
{
    /**
     * @param  list<int>  $ids
     * @return array{_notes: string, data: array{results: array<string, array{deleted: bool, error?: string}>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Field IDs to delete. Stored values for these fields will be removed from all elements.')]
        #[Validate('required')]
        array $ids = [],
    ): array|ToolOutput {
        $results = [];
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                $results[(string) $id] = ['deleted' => false, 'error' => 'ID must be a numeric value.'];

                continue;
            }

            $intId = (int) $id;
            $field = Craft::$app->fields->getFieldById($intId);
            if ($field === null) {
                $results[(string) $intId] = ['deleted' => false, 'error' => "No field found with ID {$intId}."];

                continue;
            }

            try {
                $deleted = Craft::$app->fields->deleteField($field);
                $results[(string) $intId] = $deleted
                    ? ['deleted' => true]
                    : ['deleted' => false, 'error' => 'Craft refused to delete the field.'];
            } catch (\Throwable $e) {
                $results[(string) $intId] = ['deleted' => false, 'error' => $e->getMessage()];
            }
        }

        $successCount = count(array_filter($results, static fn ($r) => ($r['deleted'] ?? false) === true));
        $total = count($results);
        $notes = "Deleted {$successCount} of {$total} fields. Each removed field is detached from every field layout that referenced it and its stored values are dropped from all elements — this is not recoverable. Per-ID outcomes are in data.results.";

        return ['_notes' => $notes, 'data' => ['results' => $results]];
    }
}
