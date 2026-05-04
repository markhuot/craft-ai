<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\validators\ExistingEntry;
use markhuot\craftai\validators\ExistingSite;

/**
 * List all drafts for a given canonical entry. Pass the canonical entry's
 * `id` (not a `draftId`). To fetch a single draft directly — including a
 * fresh draft that has no canonical entry yet — use `get_draft` with the
 * `draftId` returned by `upsert_draft`.
 */
class GetDrafts extends Tool
{
    /**
     * @return list<array<array-key, mixed>>
     */
    public function __invoke(
        #[Description('Canonical entry ID whose drafts should be listed. This must be the `id` of a published canonical entry — not a `draftId`, and not the `id` of a fresh draft (which has no canonical). For fetching a single draft, use `get_draft` instead.')]
        #[Validate('required')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entry = null,
        #[Description('Site handle for multi-site installs (e.g. "english", "french")')]
        #[Validate(ExistingSite::class)]
        ?string $site = null,
        #[Description('Sort order (e.g. "dateUpdated DESC", "dateCreated ASC")')]
        ?string $orderBy = null,
        #[Description('Maximum number of drafts to return (default 25)')]
        ?int $limit = 25,
        #[Description('Number of drafts to skip for pagination')]
        ?int $offset = null,
    ): array {
        assert($entry instanceof Entry);

        $query = Entry::find()
            ->draftOf($entry->id)
            ->status(null);

        if ($site !== null) {
            $query->site($site);
        }

        $query->orderBy($orderBy ?? 'dateUpdated DESC');
        $query->limit($limit);

        if ($offset !== null) {
            $query->offset($offset);
        }

        return array_values(array_map(
            static fn (Entry $draft): array => $draft->toArray(),
            $query->all(),
        ));
    }
}
