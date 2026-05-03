<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\validators\ExistingDraft;
use markhuot\craftai\validators\ExistingEntry;

/**
 * Create or update a draft of a content entry. Pass `draftId` to update an
 * existing draft; omit it to create a new draft of the canonical `entry`
 * (required when creating). Returns the saved draft on success.
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
        #[Description('Canonical entry ID to draft from. Required when creating.')]
        #[Validate('required', whenMissing: 'draftId')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entry = null,
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
        #[Description('Custom field values keyed by field handle (e.g. {"body": "Hello"})')]
        ?array $fields = null,
    ): array|ToolOutput {
        $isUpdate = $draftId instanceof Entry;

        if ($isUpdate) {
            $draft = $draftId;
        } else {
            assert($entry instanceof Entry);

            $creatorId = null;
            if (($user = Craft::$app->user->getIdentity()) !== null) {
                $userId = $user->getId();
                $creatorId = is_numeric($userId) ? (int) $userId : null;
            }

            $draft = Craft::$app->drafts->createDraft(
                $entry,
                $creatorId,
                $name,
                $notes,
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
