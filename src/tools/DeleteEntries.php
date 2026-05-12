<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;

/**
 * Delete one or more entries by ID. By default entries are soft-deleted and
 * can be restored from the trash; pass `hardDelete` to permanently remove
 * them. Returns a per-ID result map.
 */
#[ToolAttribute(annotations: [
    'title' => 'Delete entries',
    'destructiveHint' => true,
    'idempotentHint' => true,
    'readOnlyHint' => false,
    'openWorldHint' => false,
])]
class DeleteEntries extends Tool
{
    /**
     * @param  list<int>  $ids
     * @return array{_notes: string, data: array{results: array<string, array{deleted: bool, error?: string}>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Entry IDs to delete.')]
        #[Validate('required')]
        array $ids = [],
        #[Description('When true, permanently delete instead of moving to trash. Defaults to false.')]
        ?bool $hardDelete = null,
    ): array|ToolOutput {
        $results = [];
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                $results[(string) $id] = ['deleted' => false, 'error' => 'ID must be a numeric value.'];

                continue;
            }

            $intId = (int) $id;
            $entry = Entry::find()->id($intId)->status(null)->one();
            if (! $entry instanceof Entry) {
                $results[(string) $intId] = ['deleted' => false, 'error' => "No entry found with ID {$intId}."];

                continue;
            }

            try {
                $deleted = Craft::$app->elements->deleteElement($entry, $hardDelete ?? false);
                $results[(string) $intId] = $deleted
                    ? ['deleted' => true]
                    : ['deleted' => false, 'error' => 'Craft refused to delete the entry.'];
            } catch (\Throwable $e) {
                $results[(string) $intId] = ['deleted' => false, 'error' => $e->getMessage()];
            }
        }

        $successCount = count(array_filter($results, static fn ($r) => ($r['deleted'] ?? false) === true));
        $total = count($results);
        $mode = ($hardDelete ?? false) ? 'hard-deleted' : 'soft-deleted (recoverable from the trash)';
        $notes = "{$mode} {$successCount} of {$total} entries. Per-ID outcomes are in data.results — common failure causes are unknown IDs, the ID belonging to a different element type, or insufficient permission. Soft-deleted entries can be restored from Craft's trash until purged.";

        return ['_notes' => $notes, 'data' => ['results' => $results]];
    }
}
