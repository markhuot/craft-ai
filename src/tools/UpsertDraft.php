<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\binders\EntryType as EntryTypeBinder;
use markhuot\craftai\binders\Section as SectionBinder;
use markhuot\craftai\binders\Site as SiteBinder;
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
 */
class UpsertDraft extends Tool
{
    /**
     * @param  array<string, mixed>|null  $fields  Custom field values keyed by field handle
     * @return array<array-key, mixed>|ToolOutput
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
        #[Description('Site handle for multi-site installs (e.g. "english", "french"). Used only when creating a fresh draft.')]
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
        #[Description('Custom field values as a flat object keyed by field handle, e.g. {"body": "Hello", "summary": "..."}. Do NOT wrap in an array or use numeric keys like {"0": {"body": "Hello"}} — keys must be the field handles themselves.')]
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
            $draft = Craft::$app->drafts->createDraft(
                $entry,
                $creatorId,
                $name,
                $notes,
            );
        } elseif ($section instanceof Section) {
            if (! $type instanceof EntryType) {
                return new ToolOutput(
                    'Could not save draft: section "'.$section->handle.'" has no entry types.',
                    isError: true,
                );
            }

            $draft = new Entry();
            $draft->sectionId = $section->id;
            $draft->typeId = $type->id;

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

            return $draft->toArray();
        } else {
            return new ToolOutput(
                'Could not save draft: pass `draftId` to update, `entry` to draft an existing entry, or `section` to create a fresh draft.',
                isError: true,
            );
        }

        if ($isUpdate && $name !== null) {
            $draft->draftName = $name;
        }

        if ($isUpdate && $notes !== null) {
            $draft->draftNotes = $notes;
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

        return $draft->toArray();
    }
}
