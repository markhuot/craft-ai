<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;

/**
 * Delete one or more drafts by draft ID. The canonical entry the draft was
 * derived from is left untouched. Returns a per-ID result map.
 */
#[ToolAttribute(annotations: [
    'title' => 'Delete drafts',
    'destructiveHint' => true,
    'idempotentHint' => true,
    'readOnlyHint' => false,
    'openWorldHint' => false,
])]
class DeleteDrafts extends Tool
{
    public const KIND = ToolKind::DraftWrite;

    /**
     * @param  list<int>  $ids
     * @return array{_notes: string, data: array{results: array<string, array{deleted: bool, error?: string}>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Draft IDs to delete (the value Craft assigns to draftId, not the canonical entry ID).')]
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
            $draft = Entry::find()->draftId($intId)->status(null)->one();
            if (! $draft instanceof Entry) {
                $results[(string) $intId] = ['deleted' => false, 'error' => "No draft found with ID {$intId}."];

                continue;
            }

            try {
                $deleted = Craft::$app->elements->deleteElement($draft, true);
                $results[(string) $intId] = $deleted
                    ? ['deleted' => true]
                    : ['deleted' => false, 'error' => 'Craft refused to delete the draft.'];
            } catch (\Throwable $e) {
                $results[(string) $intId] = ['deleted' => false, 'error' => $e->getMessage()];
            }
        }

        $successCount = count(array_filter($results, static fn ($r) => ($r['deleted'] ?? false) === true));
        $total = count($results);
        $notes = "Hard-deleted {$successCount} of {$total} drafts. The canonical (published) entry each draft was derived from is left untouched — only the unpublished draft revisions are removed. Failures (unknown draftId, wrong ID supplied, etc.) are listed in data.results.";

        return ['_notes' => $notes, 'data' => ['results' => $results]];
    }
}
