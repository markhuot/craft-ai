<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\diff\VersionRef;
use markhuot\craftai\validators\ExistingEntry;
use markhuot\craftai\validators\ExistingSite;

/**
 * List the revisions of a canonical entry, most recent first. Each row carries
 * the revision's element id (use it as `rev:<id>` with `diff_revisions` /
 * `get_revision`), its revision number, who saved it, and any notes.
 *
 * Pass the canonical entry's `id` (not a `draftId` or `revisionId`).
 */
class GetRevisions extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<string, mixed>>}
     */
    public function __invoke(
        #[Description('Canonical entry ID whose revisions should be listed (the `id` of a published canonical entry).')]
        #[Validate('required')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entry = null,
        #[Description('Site handle for multi-site installs (e.g. "english"). Defaults to the primary site.')]
        #[Validate(ExistingSite::class)]
        ?string $site = null,
        #[Description('Maximum number of revisions to return (default 25).')]
        ?int $limit = 25,
    ): array {
        assert($entry instanceof Entry);

        $query = Entry::find()
            ->revisionOf($entry->id)
            ->status(null)
            ->orderBy('dateCreated DESC');

        if ($site !== null) {
            $query->site($site);
        }
        $query->limit($limit);

        $data = [];
        foreach ($query->all() as $revision) {
            $behavior = VersionRef::revisionBehavior($revision);
            $data[] = [
                'revisionId' => (int) $revision->id,
                'ref' => VersionRef::refFor($revision),
                'revisionNum' => $behavior?->revisionNum,
                'notes' => $behavior?->revisionNotes,
                'savedBy' => $behavior?->getCreator()?->username,
                'dateCreated' => $revision->dateCreated?->format(\DateTimeInterface::ATOM),
            ];
        }

        // Sort newest-first by revision number — deterministic even when two
        // revisions share a dateCreated (rapid successive saves).
        usort($data, static fn (array $x, array $y): int => ($y['revisionNum'] ?? 0) <=> ($x['revisionNum'] ?? 0));

        $notes = $data === []
            ? "Canonical entry #{$entry->id} has no revisions yet."
            : 'Returned '.count($data)." revision(s) of canonical entry #{$entry->id}, newest first. Use the `ref` (e.g. \"rev:123\") with diff_revisions to compare two, or with get_revision to read one.";

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
