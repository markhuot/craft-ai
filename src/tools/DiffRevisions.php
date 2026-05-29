<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\binders\Site as SiteBinder;
use markhuot\craftai\diff\RevisionDiffService;
use markhuot\craftai\diff\VersionRef;
use markhuot\craftai\validators\ExistingEntry;
use markhuot\craftai\validators\ExistingSite;

/**
 * Compute a deterministic, field-by-field diff between two versions of an
 * entry. Returns a structured result (per-field status + detail, with
 * word-level text diffs, relation add/remove, and Matrix block changes) that
 * you should narrate for the user rather than dumping verbatim.
 *
 * Version refs (`a` and `b`):
 *   - "current" — the live canonical entry
 *   - "rev:<id>" — a revision, by the element id from get_revisions
 *   - "draft:<id>" — a draft, by its draftId
 *   - a bare integer — shorthand for a revision id
 *
 * Use get_revisions first to discover which revisions exist.
 */
class DiffRevisions extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: array<string, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Canonical entry ID whose versions are being compared.')]
        #[Validate('required')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int|null $entryId = null,
        #[Description('First version ref: "current", "rev:<id>", "draft:<id>", or a bare revision id.')]
        #[Validate('required')]
        string $a = 'current',
        #[Description('Second version ref, same format as `a`. Defaults to "current".')]
        #[Validate('required')]
        string $b = 'current',
        #[Description('Site handle or ID for multi-site installs. Both versions are read on this locale.')]
        #[Validate(ExistingSite::class)]
        #[Bind(SiteBinder::class)]
        Site|string|int|null $site = null,
    ): array|ToolOutput {
        assert($entryId instanceof Entry);

        $canonicalId = (int) $entryId->id;
        $siteId = $site instanceof Site ? $site->id : $entryId->siteId;

        $left = VersionRef::resolve($canonicalId, $a, $siteId);
        if ($left === null) {
            return new ToolOutput("Could not resolve version \"{$a}\" for entry #{$canonicalId}. Use \"current\", \"rev:<id>\", or \"draft:<id>\"; call get_revisions to list valid revision ids.", isError: true);
        }

        $right = VersionRef::resolve($canonicalId, $b, $siteId);
        if ($right === null) {
            return new ToolOutput("Could not resolve version \"{$b}\" for entry #{$canonicalId}. Use \"current\", \"rev:<id>\", or \"draft:<id>\"; call get_revisions to list valid revision ids.", isError: true);
        }

        $diff = (new RevisionDiffService())->diff($left, $right);

        $summary = $diff['summary'];
        $labelA = is_string($diff['a']['label'] ?? null) ? $diff['a']['label'] : '';
        $labelB = is_string($diff['b']['label'] ?? null) ? $diff['b']['label'] : '';
        $notes = sprintf(
            'Diff of %s vs %s for entry #%d: %d changed, %d added, %d removed, %d unchanged. Narrate the changes that matter — don\'t echo the raw structure.',
            $labelA,
            $labelB,
            $canonicalId,
            $summary['changed'],
            $summary['added'],
            $summary['removed'],
            $summary['unchanged'],
        );

        return [
            '_notes' => $notes,
            'data' => $diff,
        ];
    }
}
