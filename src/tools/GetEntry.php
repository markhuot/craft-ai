<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\binders\Site as SiteBinder;
use markhuot\craftai\tools\concerns\BuildsSiteContextNotes;
use markhuot\craftai\validators\ExistingEntry;
use markhuot\craftai\validators\ExistingSite;

/**
 * Retrieve a single canonical content entry by its `id`. Returns full entry
 * details including all custom field values, or an error if no canonical
 * entry has that id. Drafts are NOT returned here — use `get_draft` with a
 * `draftId` to fetch a draft (including freshly created drafts that have no
 * canonical entry yet).
 *
 * On multi-site installs, pass `site` to fetch the entry as it exists on
 * that locale. Without `site`, the entry comes back on the default site,
 * which is often *not* what an agent translating content wants. The
 * response's `_notes` always names the site the entry was returned for so
 * the agent can reason about which locale it's looking at, and flags any
 * custom fields whose translationMethod is not "site" — those fields share
 * their value across locales and saving them via upsert_entry with a
 * non-primary site will overwrite the source.
 */
class GetEntry extends Tool
{
    use BuildsSiteContextNotes;

    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: array<array-key, mixed>}
     */
    public function __invoke(
        #[Description('The canonical entry ID to look up. To fetch a draft, use `get_draft` with the `draftId` instead.')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int $id,
        #[Description('Site handle or ID to read the entry from (e.g. "spanish"). Defaults to the install\'s primary site. Critical for multi-site installs — entries can store distinct values per site.')]
        #[Validate(ExistingSite::class)]
        #[Bind(SiteBinder::class)]
        Site|string|int|null $site = null,
    ): array {
        if (! $id instanceof Entry) {
            throw new \LogicException('Entry was not bound before invocation.');
        }

        $entry = $id;
        if ($site instanceof Site && $entry->siteId !== $site->id) {
            $reFetched = Entry::find()
                ->id($entry->id)
                ->siteId($site->id)
                ->status(null)
                ->one();
            if ($reFetched instanceof Entry) {
                $entry = $reFetched;
            }
        }

        $siteContext = $this->renderSiteContext($this->siteOfEntry($entry));
        $notes = "Canonical entry #{$entry->id} loaded{$siteContext}. Use upsert_entry with id={$entry->id} to publish edits, upsert_draft with entry={$entry->id} to start an editorial draft, or get_drafts with entry={$entry->id} to list its drafts."
            .$this->renderTranslationCaution($entry);

        return [
            '_notes' => $notes,
            'data' => $entry->toArray(),
        ];
    }
}
