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
 *
 * ## Pinning a comment to specific text in a CKEditor (or other HTML)
 * field
 *
 * For long-form CKEditor fields you MUST pin the indicator to a
 * specific range of prose — a whole-field comment on a CKEditor field
 * is rejected, because the editor can't tell which sentence the
 * feedback is about. Pin the indicator to that exact range instead of
 * dotting the whole field heading. This is what the "Comment" toolbar
 * plugin in the CP does for human editors, but **you do the same
 * yourself**:
 *
 *   1. Mint a referenceId. Any short string works (max 64 chars); a
 *      UUID is recommended (e.g. `crypto.randomUUID()`-style format).
 *   2. Edit the field HTML via `upsert_entry` or `upsert_draft` to
 *      wrap the target text in:
 *      `<span class="craft-ai-comment-mark" data-craft-ai-comment-id="<referenceId>">target text</span>`
 *      Read the field with `get_entry`/`get_draft` first so you can
 *      preserve the surrounding markup verbatim — only the target
 *      range gets the new wrapper.
 *   3. Call `leave_comment` with `entryId`/`draftId`, `fieldHandle`,
 *      `referenceId` matching the span, and `body` for the feedback.
 *
 * When the comment is later resolved (via the CP or your own
 * `resolve_comment` call) the server strips the span from the field
 * HTML automatically, so you don't have to clean it up yourself.
 *
 * Worked example — adding a span-scoped comment to entry #123's
 * `bodyContent` field on the second paragraph:
 *
 *   // 1. read the current HTML
 *   get_entry(entryId=123)
 *   // bodyContent = "<p>First paragraph…</p><p>Second paragraph that
 *   //                buries the lede.</p><p>Third paragraph…</p>"
 *
 *   // 2. wrap the target range with a fresh referenceId
 *   upsert_entry(id=123, fields={ bodyContent: "<p>First paragraph…</p>"
 *     + "<p><span class=\"craft-ai-comment-mark\" "
 *     + "data-craft-ai-comment-id=\"a3f1c2d8-…\">Second paragraph that "
 *     + "buries the lede.</span></p><p>Third paragraph…</p>" })
 *
 *   // 3. file the comment against that referenceId
 *   leave_comment(entryId=123, fieldHandle="bodyContent",
 *     referenceId="a3f1c2d8-…",
 *     body="Lead with the conclusion — this paragraph buries the lede.")
 *
 * Omit `referenceId` for field-level comments (the indicator dots the
 * field heading) on non-CKEditor fields — that remains correct for
 * feedback about a `title`, image, or other short field as a whole.
 * CKEditor fields are the exception: they always require a span.
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

        #[Description('Stable id pinning this comment to a specific span of text inside the field. REQUIRED for CKEditor fields (a whole-field comment on a CKEditor field is rejected); optional for other fields. To use it: pick any short string (UUID recommended), wrap the target text in the field HTML with `<span class="craft-ai-comment-mark" data-craft-ai-comment-id="<your-id>">…</span>` via `upsert_entry`/`upsert_draft`, then pass the SAME id here. The indicator will land on that exact text instead of the field heading. Requires `fieldHandle`. Leave null only for whole-field comments on non-CKEditor fields. The marker is automatically stripped on resolve.')]
        #[Validate('string', max: 64)]
        ?string $referenceId = null,
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

        $normalizedReferenceId = $referenceId !== null && $referenceId !== '' ? $referenceId : null;
        $normalizedFieldHandle = $fieldHandle !== null && $fieldHandle !== '' ? $fieldHandle : null;

        if ($normalizedReferenceId !== null && $normalizedFieldHandle === null) {
            // A reference id only makes sense when scoped to a specific
            // CKEditor field. Without the handle the overlay has no
            // anchor to scan for the marker span.
            return new ToolOutput(
                'leave_comment: `referenceId` requires `fieldHandle` to identify which CKEditor field the marker belongs to.',
                isError: true,
            );
        }

        // CKEditor fields support pinning a comment to an individual span
        // of prose, and a whole-field dot on a long-form field is rarely
        // actionable — the editor can't tell which sentence the feedback
        // is about. Require the span anchor for CKEditor targets: bail
        // unless the caller also wrapped the text and passed a matching
        // `referenceId`. Plain-text/other fields keep the field-level dot.
        if ($normalizedFieldHandle !== null && $normalizedReferenceId === null) {
            $field = $element->getFieldLayout()?->getFieldByHandle($normalizedFieldHandle);
            if ($field instanceof \craft\ckeditor\Field) {
                return new ToolOutput(
                    sprintf(
                        'leave_comment: comments on the CKEditor field `%s` must be pinned to a specific span of text, '
                        .'not the field as a whole. Wrap the target text in the field HTML with '
                        .'`<span class="craft-ai-comment-mark" data-craft-ai-comment-id="<your-id>">…</span>` via '
                        .'`upsert_entry`/`upsert_draft`, then call leave_comment again passing that same id as `referenceId`.',
                        $normalizedFieldHandle,
                    ),
                    isError: true,
                );
            }
        }

        $record = new CommentRecord();
        $record->sessionId = $sessionId;
        $record->elementId = $targetId;
        $record->isDraft = $isDraft;
        $record->fieldHandle = $normalizedFieldHandle;
        $record->referenceId = $normalizedReferenceId;
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
                'referenceId' => $record->referenceId,
                'body' => $record->body,
                'status' => $record->status,
            ],
        ];
    }
}
