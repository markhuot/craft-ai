<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\helpers\CommentScope;
use markhuot\craftai\records\CommentRecord;
use markhuot\craftai\validators\ExistingDraft;
use markhuot\craftai\validators\ExistingEntry;

/**
 * List review comments attached to an entry or draft.
 *
 * Pass either `entryId` or `draftId` to scope. Use `status: "open"` (the
 * default) to fetch unresolved comments only — handy at the start of a
 * follow-up review to see what's still outstanding from a prior session.
 * Use `status: "all"` to retrieve resolved comments too, e.g. when the
 * user is asking why something was changed.
 *
 * The scope is recursive: passing a page entry returns comments on the
 * entry itself plus comments on any Matrix block (or nested-matrix block)
 * inside it. Each row carries its own `elementId` so you can tell which
 * level the comment lives on.
 */
class GetComments extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<string, mixed>>}|ToolOutput
     */
    public function __invoke(
        #[Description('Canonical entry ID to list comments for. Provide either entryId or draftId, not both.')]
        #[Validate(ExistingEntry::class, whenMissing: 'draftId')]
        #[Validate('required', whenMissing: 'draftId')]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entryId = null,

        #[Description('Draft ID (the value Craft assigns to draftId, not the canonical entry ID).')]
        #[Validate(ExistingDraft::class, whenMissing: 'entryId')]
        #[Validate('required', whenMissing: 'entryId')]
        #[Bind(DraftBinder::class)]
        Entry|int|null $draftId = null,

        #[Description('Filter by status: "open" (default), "resolved", or "all".')]
        #[Validate('in', range: ['open', 'resolved', 'all'])]
        string $status = 'open',
    ): array|ToolOutput {
        if ($entryId !== null && $draftId !== null) {
            return new ToolOutput(
                'Pass exactly one of entryId or draftId — get_comments cannot scope to both at once.',
                isError: true,
            );
        }

        $element = $entryId instanceof Entry ? $entryId : ($draftId instanceof Entry ? $draftId : null);

        if (! $element instanceof Entry) {
            return new ToolOutput(
                'Could not resolve the target entry or draft.',
                isError: true,
            );
        }

        $isDraft = $draftId instanceof Entry;
        $targetId = $isDraft ? (int) $element->draftId : (int) $element->id;

        $pairs = CommentScope::pairsFor($targetId, $isDraft);

        $query = CommentRecord::find()
            ->orderBy(['id' => SORT_ASC]);

        // OR of (elementId, isDraft) pairs — we can't just use IN on the
        // IDs because a canonical id and a draftId share the same integer
        // namespace and only the boolean disambiguates them.
        $orClauses = ['or'];
        foreach ($pairs as [$id, $pairIsDraft]) {
            $orClauses[] = ['and', ['elementId' => $id], ['isDraft' => $pairIsDraft]];
        }
        $query->andWhere($orClauses);

        if ($status !== 'all') {
            $query->andWhere(['status' => $status]);
        }

        /** @var list<CommentRecord> $records */
        $records = $query->all();

        $comments = array_map(static fn (CommentRecord $r): array => [
            'id' => (int) $r->id,
            'sessionId' => $r->sessionId,
            'elementId' => (int) $r->elementId,
            'isDraft' => (bool) $r->isDraft,
            'fieldHandle' => $r->fieldHandle,
            'body' => $r->body,
            'status' => $r->status,
            'resolvedAt' => $r->resolvedAt,
            'resolvedBy' => $r->resolvedBy,
            'dateCreated' => $r->dateCreated,
        ], $records);

        $scopeLabel = $isDraft ? "draft #{$targetId}" : "entry #{$targetId}";
        $count = count($comments);
        $statusLabel = $status === 'all' ? 'total' : $status;

        return [
            '_notes' => sprintf(
                '%d %s comment(s) on %s.',
                $count,
                $statusLabel,
                $scopeLabel,
            ),
            'data' => $comments,
        ];
    }
}
