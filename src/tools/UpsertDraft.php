<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\behaviors\DraftBehavior;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\binders\EntryType as EntryTypeBinder;
use markhuot\craftai\binders\Section as SectionBinder;
use markhuot\craftai\binders\Site as SiteBinder;
use markhuot\craftai\helpers\DraftPreview;
use markhuot\craftai\helpers\PreviewSuggestion;
use markhuot\craftai\validators\ExistingDraft;
use markhuot\craftai\validators\ExistingEntry;
use markhuot\craftai\validators\ExistingEntryType;
use markhuot\craftai\validators\ExistingSection;
use markhuot\craftai\validators\ExistingSite;

/**
 * Create or update a draft of a content entry. Pass `draftId` to update an
 * existing draft. To create a draft of an existing entry, pass `entry`. To
 * create a fresh draft with no canonical entry yet, pass `section` (and
 * optionally `type`) instead. Returns the saved draft on success.
 *
 * The returned payload includes both `id` (the draft element's id) and
 * `draftId` (Craft's draft pointer). To re-fetch the draft later, call
 * `get_draft` with `draftId` — `get_entry` will not find drafts, and
 * `get_drafts` requires the canonical entry id (which a fresh draft does
 * not have).
 *
 * Matrix fields accept an object keyed by block ID. Each block is itself an
 * object with `type` (the block type's entry-type handle — see
 * `settings.entryTypes[].handle` from `get_fields`) and `fields` (the sub-field
 * values keyed by handle). Existing blocks are referenced by their numeric ID;
 * new blocks use placeholder keys like `"new1"`, `"new2"`, etc. — any string
 * key that is not an existing block ID is treated as a new block. The order
 * of keys determines the order of blocks; blocks omitted from the object are
 * deleted on save. Example:
 *
 *     {
 *       "contentBuilder": {
 *         "42":   {"type": "text",    "fields": {"body": "Existing block, updated"}},
 *         "new1": {"type": "heading", "fields": {"heading": "New block at the end"}}
 *       }
 *     }
 */
class UpsertDraft extends Tool
{
    public const KIND = ToolKind::DraftWrite;

    public function __construct(
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    /**
     * @param  array<string, mixed>|null  $fields  Custom field values keyed by field handle
     * @return array{_notes: string, data: array<array-key, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Existing draft ID to update. Omit to create a new draft.')]
        #[Validate(ExistingDraft::class)]
        #[Bind(DraftBinder::class)]
        Entry|int|null $draftId = null,
        #[Description('Canonical entry ID to draft from. Omit (and pass `section` instead) to create a fresh draft with no canonical entry.')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entry = null,
        #[Description('Section handle for a fresh draft (no canonical entry). Used only when both `draftId` and `entry` are omitted.')]
        #[Validate(ExistingSection::class)]
        #[Bind(SectionBinder::class)]
        Section|string|int|null $section = null,
        #[Description('Entry type handle for a fresh draft (defaults to the section\'s first entry type).')]
        #[Validate(ExistingEntryType::class, inSection: 'section')]
        #[Bind(EntryTypeBinder::class, inSection: 'section', defaultToFirst: true)]
        EntryType|string|int|null $type = null,
        #[Description('Site handle or ID for multi-site installs (e.g. "spanish"). When creating a draft from a canonical `entry`, the draft is anchored to this site so subsequent saves edit the locale\'s row instead of the source. Ignored when updating an existing draft (drafts are already locked to a site).')]
        #[Validate(ExistingSite::class)]
        #[Bind(SiteBinder::class)]
        Site|string|int|null $site = null,
        #[Description('Author user ID for a fresh draft (defaults to the current user).')]
        ?int $authorId = null,
        #[Description('Draft name (e.g. "Editorial pass"). Auto-generated when creating if omitted.')]
        #[Validate('string', max: 255)]
        ?string $name = null,
        #[Description('Draft notes / changelog message')]
        ?string $notes = null,
        #[Description('Updated entry title')]
        #[Validate('string', max: 255)]
        ?string $title = null,
        #[Description('Updated URL slug')]
        ?string $slug = null,
        #[Description('Custom field values as a flat object keyed by field handle, e.g. {"body": "Hello", "summary": "..."}. Do NOT wrap in an array or use numeric keys like {"0": {"body": "Hello"}} — keys must be the field handles themselves. Matrix fields take an object keyed by block ID where each block is {"type": "<entryTypeHandle>", "fields": {...}}; use "new1", "new2" (or any unused string) for new blocks and existing numeric IDs for blocks you want to keep or update. See the tool description for a full example.')]
        ?array $fields = null,
    ): array|ToolOutput {
        $isUpdate = $draftId instanceof Entry;

        $creatorId = null;
        if (($user = Craft::$app->user->getIdentity()) !== null) {
            $userId = $user->getId();
            $creatorId = is_numeric($userId) ? (int) $userId : null;
        }

        if ($isUpdate) {
            $draft = $draftId;
        } elseif ($entry instanceof Entry) {
            // Drafts are anchored to a specific site row of the canonical.
            // The Entry binder resolved against the default site, so two
            // things have to happen to land a draft on a non-default site:
            //
            // 1. Prefer the canonical's row on the target site when it
            //    exists — duplicateElement uses the source instance's
            //    siteId as the starting point for the draft's field
            //    values.
            // 2. Pass siteId through `newAttributes` so Craft creates the
            //    draft's own per-site row on the target site (otherwise it
            //    inherits the source instance's siteId silently).
            //
            // Step 2 is the load-bearing piece: sections with
            // propagationMethod="custom" don't auto-propagate entries to
            // new sites, so the canonical may only exist on the primary
            // even though the section is enabled on others. Without (2)
            // the draft lands on the wrong locale and subsequent saves
            // silently overwrite the source.
            $canonical = $entry;
            $newAttributes = [];
            if ($site instanceof Site && $entry->siteId !== $site->id) {
                if (! $this->sectionEnabledForSite($entry, $site)) {
                    $section = $entry->getSection();
                    $sectionHandle = $section !== null ? $section->handle : '(unknown)';
                    $siteHandle = $site->handle ?? (string) $site->id;
                    return new ToolOutput(
                        "Cannot create a draft on site \"{$siteHandle}\" — section \"{$sectionHandle}\" is not enabled for that site. Re-call upsert_section with `sites` extended to include \"{$siteHandle}\" first.",
                        isError: true,
                    );
                }
                $resolved = Entry::find()
                    ->id($entry->id)
                    ->siteId($site->id)
                    ->status(null)
                    ->one();
                if ($resolved instanceof Entry) {
                    $canonical = $resolved;
                }
                $newAttributes['siteId'] = $site->id;
            }

            $draft = Craft::$app->drafts->createDraft(
                $canonical,
                $creatorId,
                $name,
                $notes,
                $newAttributes,
            );
        } elseif ($section instanceof Section) {
            if (! $type instanceof EntryType) {
                return new ToolOutput(
                    'Could not save draft: section "'.$section->handle.'" has no entry types.',
                    isError: true,
                );
            }

            $typeId = $type->id;
            assert($typeId !== null);
            $draft = new Entry();
            $draft->sectionId = $section->id;
            $draft->typeId = $typeId;

            if ($site instanceof Site) {
                $draft->siteId = $site->id;
            }

            if ($authorId !== null) {
                $draft->setAuthorIds([$authorId]);
            } elseif ($creatorId !== null) {
                $draft->setAuthorIds([$creatorId]);
            }

            if ($title !== null) {
                $draft->title = $title;
            }

            if ($slug !== null) {
                $draft->slug = $slug;
            }

            if ($fields !== null) {
                $draft->setFieldValues($fields);
            }

            if (! Craft::$app->drafts->saveElementAsDraft($draft, $creatorId, $name, $notes)) {
                $errors = $draft->getErrorSummary(true);

                return new ToolOutput(
                    'Could not save draft: '.implode('; ', $errors),
                    isError: true,
                );
            }

            $url = DraftPreview::urlFor($draft);
            $data = $draft->toArray();
            $data['url'] = $url;

            $cpEditUrl = null;
            try {
                $cpEditUrl = $draft->getCpEditUrl();
            } catch (\Throwable) {
                $cpEditUrl = null;
            }

            return PreviewSuggestion::wrap(
                notes: sprintf(
                    'Fresh draft created with draftId=%d (no canonical entry yet). Use get_draft with draftId to re-fetch, or call upsert_draft again with this draftId to keep iterating before publishing.',
                    $draft->draftId,
                ),
                data: $data,
                key: 'draft',
                url: $url,
                context: $this->context,
                cpEditUrl: $cpEditUrl,
            );
        } else {
            return new ToolOutput(
                'Could not save draft: pass `draftId` to update, `entry` to draft an existing entry, or `section` to create a fresh draft.',
                isError: true,
            );
        }

        if ($isUpdate && ($name !== null || $notes !== null)) {
            $behavior = $draft->getBehavior('draft');
            if ($behavior instanceof DraftBehavior) {
                if ($name !== null) {
                    $behavior->draftName = $name;
                }
                if ($notes !== null) {
                    $behavior->draftNotes = $notes;
                }
            }
        }

        if ($title !== null) {
            $draft->title = $title;
        }

        if ($slug !== null) {
            $draft->slug = $slug;
        }

        if ($fields !== null) {
            $draft->setFieldValues($fields);
        }

        if (! Craft::$app->elements->saveElement($draft)) {
            $errors = $draft->getErrorSummary(true);

            return new ToolOutput(
                'Could not save draft: '.implode('; ', $errors),
                isError: true,
            );
        }

        $url = DraftPreview::urlFor($draft);
        $data = $draft->toArray();
        $data['url'] = $url;

        $cpEditUrl = null;
        try {
            $cpEditUrl = $draft->getCpEditUrl();
        } catch (\Throwable) {
            $cpEditUrl = null;
        }

        $notes = $isUpdate
            ? sprintf(
                'Updated draft draftId=%d. Use get_draft to re-fetch the saved state, or upsert_entry on the canonical entry once you are ready to publish.',
                $draft->draftId,
            )
            : sprintf(
                'Created draft draftId=%d from canonical entry id=%d. Use get_draft with draftId to re-fetch — get_entry will not find drafts.',
                $draft->draftId,
                $draft->canonicalId ?? 0,
            );

        return PreviewSuggestion::wrap(
            notes: $notes,
            data: $data,
            key: 'draft',
            url: $url,
            context: $this->context,
            cpEditUrl: $cpEditUrl,
        );
    }

    /**
     * Whether the canonical entry's section has a site_settings row for
     * the target site. If false, the draft cannot live on that site and
     * we error early with actionable guidance — Craft would otherwise
     * throw a less informative exception inside duplicateElement.
     */
    private function sectionEnabledForSite(Entry $canonical, Site $site): bool
    {
        $section = $canonical->getSection();
        if ($section === null) {
            return true;
        }
        foreach ($section->getSiteSettings() as $row) {
            if ((int) $row->siteId === $site->id) {
                return true;
            }
        }

        return false;
    }
}
