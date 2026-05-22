<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\binders\Site as SiteBinder;
use markhuot\craftai\helpers\DraftPreview;
use markhuot\craftai\tools\concerns\BuildsSiteContextNotes;
use markhuot\craftai\validators\ExistingDraft;
use markhuot\craftai\validators\ExistingSite;

/**
 * Retrieve a single draft by its `draftId`. Use this to re-fetch a draft
 * returned by `upsert_draft` — `get_entry` will not find drafts, since drafts
 * are excluded from the canonical entry index.
 *
 * On multi-site installs, drafts of a multi-site canonical exist as
 * one row per site (with potentially different per-site field values).
 * Pass `site` to read the draft on that locale; otherwise the draft is
 * returned for the install's primary site, which often is *not* the
 * locale the agent is translating into. The response `_notes` always
 * names the site the row came back from so the agent doesn't mistake a
 * primary-site view for a "save failed" signal, and flags any custom
 * fields whose translationMethod is not "site" — those are shared
 * across locales.
 */
class GetDraft extends Tool
{
    use BuildsSiteContextNotes;

    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: array<array-key, mixed>}
     */
    public function __invoke(
        #[Description('The draftId to look up (the value Craft returns as `draftId`, not the entry `id`).')]
        #[Validate(ExistingDraft::class)]
        #[Bind(DraftBinder::class)]
        Entry|int $draftId,
        #[Description('Site handle or ID to read the draft from (e.g. "spanish"). Defaults to the install\'s primary site. Critical for multi-site installs — a draft of a multi-site canonical has one row per site, each with its own field values.')]
        #[Validate(ExistingSite::class)]
        #[Bind(SiteBinder::class)]
        Site|string|int|null $site = null,
    ): array {
        if (! $draftId instanceof Entry) {
            throw new \LogicException('Draft was not bound before invocation.');
        }

        $draft = $draftId;
        if ($site instanceof Site && $draft->siteId !== $site->id) {
            $reFetched = Entry::find()
                ->draftId($draft->draftId)
                ->siteId($site->id)
                ->status(null)
                ->one();
            if ($reFetched instanceof Entry) {
                $draft = $reFetched;
            }
        }

        $data = $draft->toArray();
        $data['url'] = DraftPreview::urlFor($draft);

        $draftIdValue = (int) $draft->draftId;
        $canonicalId = $draft->getCanonicalId();
        $siteContext = $this->renderSiteContext($this->siteOfEntry($draft));

        $notes = $canonicalId !== null && $canonicalId !== $draft->id
            ? "Draft #{$draftIdValue} of canonical entry #{$canonicalId}{$siteContext}. Use upsert_draft with draftId={$draftIdValue} to edit, or upsert_entry with id={$canonicalId} to publish changes directly."
            : "Fresh draft #{$draftIdValue} (no canonical entry yet){$siteContext}. Use upsert_draft with this draftId to edit, or upsert_entry referencing this draft to publish it.";

        $notes .= $this->renderTranslationCaution($draft);

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
