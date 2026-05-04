<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\validators\ExistingEntry;

/**
 * Retrieve a single canonical content entry by its `id`. Returns full entry
 * details including all custom field values, or an error if no canonical
 * entry has that id. Drafts are NOT returned here — use `get_draft` with a
 * `draftId` to fetch a draft (including freshly created drafts that have no
 * canonical entry yet).
 */
class GetEntry extends Tool
{
    /**
     * @return array<array-key, mixed>
     */
    public function __invoke(
        #[Description('The canonical entry ID to look up. To fetch a draft, use `get_draft` with the `draftId` instead.')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int $id,
    ): array {
        if (! $id instanceof Entry) {
            throw new \LogicException('Entry was not bound before invocation.');
        }

        return $id->toArray();
    }
}
