<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\helpers\DraftPreview;
use markhuot\craftai\validators\ExistingDraft;

/**
 * Retrieve a single draft by its `draftId`. Use this to re-fetch a draft
 * returned by `upsert_draft` — `get_entry` will not find drafts, since drafts
 * are excluded from the canonical entry index.
 */
class GetDraft extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: array<array-key, mixed>}
     */
    public function __invoke(
        #[Description('The draftId to look up (the value Craft returns as `draftId`, not the entry `id`).')]
        #[Validate(ExistingDraft::class)]
        #[Bind(DraftBinder::class)]
        Entry|int $draftId,
    ): array {
        if (! $draftId instanceof Entry) {
            throw new \LogicException('Draft was not bound before invocation.');
        }

        $data = $draftId->toArray();
        $data['url'] = DraftPreview::urlFor($draftId);

        $draftIdValue = $data['draftId'] ?? null;
        $canonicalId = $data['canonicalId'] ?? null;
        $notes = $canonicalId !== null && $canonicalId !== $data['id']
            ? "Draft #{$draftIdValue} of canonical entry #{$canonicalId}. Use upsert_draft with draftId={$draftIdValue} to edit, or upsert_entry with id={$canonicalId} to publish changes directly."
            : "Fresh draft #{$draftIdValue} (no canonical entry yet). Use upsert_draft with this draftId to edit, or upsert_entry referencing this draft to publish it.";

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
