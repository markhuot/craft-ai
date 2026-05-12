<?php

namespace markhuot\craftai\tools;

use Craft;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;

/**
 * Delete one or more entry types by ID. Returns a per-ID result map.
 */
#[ToolAttribute(annotations: [
    'title' => 'Delete entry types',
    'destructiveHint' => true,
    'idempotentHint' => true,
    'readOnlyHint' => false,
    'openWorldHint' => false,
])]
class DeleteEntryTypes extends Tool
{
    /**
     * @param  list<int>  $ids
     * @return array{_notes: string, data: array{results: array<string, array{deleted: bool, error?: string}>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Entry type IDs to delete.')]
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
            $entryType = Craft::$app->entries->getEntryTypeById($intId);
            if ($entryType === null) {
                $results[(string) $intId] = ['deleted' => false, 'error' => "No entry type found with ID {$intId}."];

                continue;
            }

            try {
                $deleted = Craft::$app->entries->deleteEntryTypeById($intId);
                $results[(string) $intId] = $deleted
                    ? ['deleted' => true]
                    : ['deleted' => false, 'error' => 'Craft refused to delete the entry type.'];
            } catch (\Throwable $e) {
                $results[(string) $intId] = ['deleted' => false, 'error' => $e->getMessage()];
            }
        }

        $successCount = count(array_filter($results, static fn ($r) => ($r['deleted'] ?? false) === true));
        $total = count($results);
        $notes = "Deleted {$successCount} of {$total} entry types. Every entry that used this entry type is also removed, and the type is detached from any sections that referenced it — re-run get_sections afterward if you need a fresh section/entry-type mapping. Failures are in data.results.";

        return ['_notes' => $notes, 'data' => ['results' => $results]];
    }
}
