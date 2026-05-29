<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Revision as RevisionBinder;
use markhuot\craftai\binders\Site as SiteBinder;
use markhuot\craftai\diff\VersionRef;
use markhuot\craftai\tools\concerns\BuildsSiteContextNotes;
use markhuot\craftai\validators\ExistingRevision;
use markhuot\craftai\validators\ExistingSite;

/**
 * Read a single entry revision by its element id (the value `get_revisions`
 * lists as `revisionId`). Returns the revision's full field values as a
 * snapshot — useful for inspecting one version, or as input alongside
 * `diff_revisions` to compare two.
 *
 * On multi-site installs, pass `site` to read the revision on that locale.
 */
class GetRevision extends Tool
{
    use BuildsSiteContextNotes;

    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: array<array-key, mixed>}
     */
    public function __invoke(
        #[Description('The revision element id to read (the `revisionId` value returned by get_revisions).')]
        #[Validate(ExistingRevision::class)]
        #[Bind(RevisionBinder::class)]
        Entry|int $revisionId,
        #[Description('Site handle or ID to read the revision from (e.g. "spanish"). Defaults to the install\'s primary site.')]
        #[Validate(ExistingSite::class)]
        #[Bind(SiteBinder::class)]
        Site|string|int|null $site = null,
    ): array {
        if (! $revisionId instanceof Entry) {
            throw new \LogicException('Revision was not bound before invocation.');
        }

        $revision = $revisionId;
        if ($site instanceof Site && $revision->siteId !== $site->id) {
            $reFetched = Entry::find()
                ->revisions(true)
                ->id($revision->id)
                ->siteId($site->id)
                ->status(null)
                ->one();
            if ($reFetched instanceof Entry) {
                $revision = $reFetched;
            }
        }

        $behavior = VersionRef::revisionBehavior($revision);
        $num = $behavior?->revisionNum;
        $canonicalId = $revision->getCanonicalId();
        $siteContext = $this->renderSiteContext($this->siteOfEntry($revision));

        $label = $num !== null ? "Revision {$num}" : 'Revision';
        $notes = "{$label} (element #{$revision->id}) of canonical entry #{$canonicalId}{$siteContext}. This is an immutable snapshot — use upsert_draft/upsert_entry on the canonical to make changes, or diff_revisions to compare it against another version.";

        return [
            '_notes' => $notes,
            'data' => $revision->toArray(),
        ];
    }
}
