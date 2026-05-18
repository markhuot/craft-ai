<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\validators\ExistingDraft;
use markhuot\craftai\validators\ExistingEntry;

/**
 * Leave a review comment on a specific field within an entry or draft.
 *
 * Use this tool when you have feedback that should surface in the Craft
 * control panel next to the relevant field — e.g. "this headline buries
 * the lede" on `title`, or "the alt text is missing context" on
 * `featuredImage`. The user can reply to the comment from the entry edit
 * screen (their reply lands as a user turn in this same chat session) or
 * mark it resolved. Use `resolve_comment` once you've confirmed the
 * feedback has been addressed.
 *
 * Pass exactly one of `entryId` (a canonical entry) or `draftId` (a draft
 * in progress). Omit `fieldHandle` to leave a top-level note about the
 * entry as a whole rather than scoping it to one field.
 *
 * Matrix blocks are first-class entries in Craft 5: when a `get_entry`
 * response nests blocks under a Matrix field (keyed by their numeric
 * IDs), each of those IDs is itself a valid `entryId` for this tool.
 * To leave feedback on a field _inside_ a Matrix block, pass the block's
 * own entry ID as `entryId` and the inner field handle as `fieldHandle`
 * — don't target the outer Matrix field on the parent entry. The dot
 * will then land on the right field inside the right block.
 */
class LeaveComment extends Tool
{
    public const KIND = ToolKind::DraftWrite;

    public function __construct(
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    /**
     * @return array{_notes: string, data: array<string, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('The text of the review comment. Be specific and actionable — the user reads this verbatim next to the field on the entry edit screen.')]
        #[Validate('string', max: 4000)]
        #[Validate('required')]
        string $body,

        #[Description('Canonical entry ID to attach this comment to. Provide either entryId or draftId, not both.')]
        #[Validate(ExistingEntry::class, whenMissing: 'draftId')]
        #[Validate('required', whenMissing: 'draftId')]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entryId = null,

        #[Description('Draft ID (the value Craft assigns to draftId, not the canonical entry ID) to attach this comment to. Provide either entryId or draftId, not both.')]
        #[Validate(ExistingDraft::class, whenMissing: 'entryId')]
        #[Validate('required', whenMissing: 'entryId')]
        #[Bind(DraftBinder::class)]
        Entry|int|null $draftId = null,

        #[Description('Field handle the comment scopes to (e.g. "title", "bodyContent"). Omit to attach a top-level note covering the whole entry. For a field inside a Matrix block, set this to the inner field handle and target the block as `entryId`/`draftId` — Matrix blocks are entries.')]
        #[Validate('string', max: 255)]
        ?string $fieldHandle = null,
    ): array|ToolOutput {
        if ($entryId !== null && $draftId !== null) {
            return new ToolOutput(
                'Pass exactly one of entryId or draftId — leave_comment cannot attach to both at once.',
                isError: true,
            );
        }

        $element = $entryId instanceof Entry ? $entryId : ($draftId instanceof Entry ? $draftId : null);

        if (! $element instanceof Entry) {
            return new ToolOutput(
                'Could not resolve the target entry or draft. Ensure entryId or draftId points to an existing element.',
                isError: true,
            );
        }

        $isDraft = $draftId instanceof Entry;
        $targetId = $isDraft ? (int) $element->draftId : (int) $element->id;

        $sessionId = $this->context->getSessionId();
        if ($sessionId === null) {
            return new ToolOutput(
                'leave_comment can only run inside an active review session.',
                isError: true,
            );
        }

        $record = new CommentRecord();
        $record->sessionId = $sessionId;
        $record->elementId = $targetId;
        $record->isDraft = $isDraft;
        $record->fieldHandle = $fieldHandle !== null && $fieldHandle !== '' ? $fieldHandle : null;
        $record->body = $body;
        $record->status = CommentRecord::STATUS_OPEN;
        // Pin to the assistant turn that emitted this tool_use so a
        // later open-thread can fork at exactly the message that left
        // the comment — not "the latest message in the session at click
        // time" (the old fallback).
        $record->authorMessageId = $this->context->getMessageId();

        if (! $record->save()) {
            return new ToolOutput(
                'Could not save the comment: '.implode('; ', array_map(
                    static fn ($errors): string => implode(', ', (array) $errors),
                    $record->getErrors(),
                )),
                isError: true,
            );
        }

        $scopeLabel = $isDraft ? "draft #{$targetId}" : "entry #{$targetId}";
        $fieldLabel = $record->fieldHandle !== null ? " on field `{$record->fieldHandle}`" : ' (top-level note)';

        return [
            '_notes' => sprintf(
                'Comment #%d saved on %s%s. The user will see it in the CP entry edit screen and can reply or resolve it. Call resolve_comment(commentId: %d) once the feedback has been addressed.',
                $record->id,
                $scopeLabel,
                $fieldLabel,
                $record->id,
            ),
            'data' => [
                'id' => (int) $record->id,
                'elementId' => (int) $record->elementId,
                'isDraft' => (bool) $record->isDraft,
                'fieldHandle' => $record->fieldHandle,
                'body' => $record->body,
                'status' => $record->status,
            ],
        ];
    }
}
