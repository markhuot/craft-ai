<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Draft as DraftBinder;
use markhuot\craftai\helpers\PreviewSuggestion;
use markhuot\craftai\validators\ExistingDraft;

/**
 * Publish a draft: apply it to its canonical entry (or promote a fresh draft
 * into a canonical entry of its own) and delete the draft. Returns the saved
 * canonical entry. Use this once a draft is ready to go live — for further
 * edits without publishing, keep calling `upsert_draft` instead.
 */
class ApplyDraft extends Tool
{
    public function __construct(
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    /**
     * @return array{_notes: string, data: array<array-key, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Draft ID to publish (the value Craft assigns to draftId, not the canonical entry ID).')]
        #[Validate(ExistingDraft::class)]
        #[Bind(DraftBinder::class)]
        Entry|int $draftId,
    ): array|ToolOutput {
        if (! $draftId instanceof Entry) {
            throw new \LogicException('Draft was not bound before invocation.');
        }

        $draft = $draftId;
        $wasFresh = $draft->getIsUnpublishedDraft();
        $originalDraftId = $draft->draftId;

        try {
            $canonical = Craft::$app->drafts->applyDraft($draft);
        } catch (\Throwable $e) {
            return new ToolOutput(
                'Could not apply draft: '.$e->getMessage(),
                isError: true,
            );
        }

        if (! $canonical instanceof Entry) {
            return new ToolOutput(
                'Could not apply draft: Craft returned an unexpected element type.',
                isError: true,
            );
        }

        $url = $canonical->getUrl();
        $data = $canonical->toArray();
        $data['url'] = $url;

        $notes = $wasFresh
            ? sprintf(
                'Published fresh draft #%d as canonical entry id=%d. The draft no longer exists; future edits should use upsert_entry (or upsert_draft to start a new draft).',
                $originalDraftId,
                $canonical->id,
            )
            : sprintf(
                'Applied draft #%d to canonical entry id=%d. The draft has been deleted; future edits should use upsert_entry (or upsert_draft to start a new draft).',
                $originalDraftId,
                $canonical->id,
            );

        return [
            '_notes' => $notes,
            'data' => PreviewSuggestion::wrap($data, $url, 'entry', $this->context),
        ];
    }
}
