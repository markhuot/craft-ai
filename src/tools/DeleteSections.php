<?php

namespace markhuot\craftai\tools;

use Craft;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;

/**
 * Delete one or more sections by ID. Deleting a section removes all entries
 * within it. Returns a per-ID result map.
 */
#[ToolAttribute(annotations: [
    'title' => 'Delete sections',
    'destructiveHint' => true,
    'idempotentHint' => true,
    'readOnlyHint' => false,
    'openWorldHint' => false,
])]
class DeleteSections extends Tool
{
    /**
     * @param  list<int>  $ids
     * @return array{_notes: string, data: array{results: array<string, array{deleted: bool, error?: string}>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Section IDs to delete. All matching entries will also be removed.')]
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
            $section = Craft::$app->entries->getSectionById($intId);
            if ($section === null) {
                $results[(string) $intId] = ['deleted' => false, 'error' => "No section found with ID {$intId}."];

                continue;
            }

            try {
                $deleted = Craft::$app->entries->deleteSectionById($intId);
                $results[(string) $intId] = $deleted
                    ? ['deleted' => true]
                    : ['deleted' => false, 'error' => 'Craft refused to delete the section.'];
            } catch (\Throwable $e) {
                $results[(string) $intId] = ['deleted' => false, 'error' => $e->getMessage()];
            }
        }

        $successCount = count(array_filter($results, static fn ($r) => ($r['deleted'] ?? false) === true));
        $total = count($results);
        $notes = "Deleted {$successCount} of {$total} sections. Every entry inside a deleted section is permanently removed along with it — this is destructive and not recoverable through the trash. Per-ID outcomes are in data.results.";

        return ['_notes' => $notes, 'data' => ['results' => $results]];
    }
}
