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
 * List all drafts for a given canonical entry. Returns each draft's ID,
 * draftId, title, creator, draft name/notes, and field values.
 */
class GetDrafts extends Tool
{
    /**
     * @return list<array<array-key, mixed>>
     */
    public function __invoke(
        #[Description('Canonical entry ID whose drafts should be listed.')]
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
