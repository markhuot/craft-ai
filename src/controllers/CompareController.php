<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\diff\DiffRenderer;
use markhuot\craftai\diff\RevisionDiffService;
use markhuot\craftai\diff\VersionRef;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\ComparisonRecord;
use markhuot\craftai\records\SessionRecord;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The "compare revisions" surface. Opened from the entry-edit sidebar button,
 * it renders a full CP page (mounting the `compare` React bundle) where the
 * editor picks any two versions of an entry and sees a deterministic diff plus
 * an AI narration of what changed and why.
 *
 * Three roles:
 *  - {@see actionIndex} renders the page + the picker bootstrap.
 *  - {@see actionDiff} is the synchronous workhorse: it runs the PHP diff,
 *    renders + persists the artifact, and (re)kicks the narration session.
 *    The pickers call it directly, so recompute is instant — no LLM on the
 *    critical path.
 *  - {@see actionRevisions} lists revisions as JSON for the pickers.
 *
 * The narration runs on the queue (a read-only session) and streams into the
 * view via the existing `/messages` poll, exactly like the chat surface.
 */
class CompareController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireLogin();

        $entry = $this->resolveEntry();
        $a = $this->refParam('a', '');
        $b = $this->refParam('b', 'current');

        return $this->renderTemplate('craft-ai/compare/view', [
            'entryId' => (int) $entry->id,
            'entryTitle' => $this->entryTitle($entry),
            // Native CP breadcrumb trail (site ▸ Entries ▸ section ▸ entry),
            // matching the entry-edit screen so the user can navigate back.
            'crumbs' => $this->buildCrumbs($entry),
            'siteId' => (int) $entry->siteId,
            'a' => $a,
            'b' => $b,
            'revisions' => $this->revisionOptions($entry),
        ]);
    }

    /**
     * The native CP breadcrumb trail leading back to the entry under
     * comparison, assembled from Craft's own crumb builders so it matches the
     * entry-edit screen exactly:
     *
     *   1. site dropdown (multi-site only) — every editable site, current one
     *      selected; picking another reloads the compare page on that site
     *      (the URLs come from {@see Cp::siteMenuItems}, keyed off this path).
     *   2. "Entries" + 3. the section dropdown — straight off the element via
     *      {@see Entry::getCrumbs()}.
     *   4. the entry itself (status icon + title) as a chip linking back to its
     *      edit screen.
     *
     * @return list<mixed>
     */
    private function buildCrumbs(Entry $entry): array
    {
        $crumbs = [];

        if (Craft::$app->getIsMultiSite()) {
            $site = $entry->getSite();
            $crumbs[] = [
                'id' => 'site-crumb',
                'icon' => Cp::earthIcon(),
                'label' => Craft::t('site', $site->name),
                'menu' => [
                    'label' => Craft::t('app', 'Select site'),
                    'items' => Cp::siteMenuItems(null, $site),
                ],
            ];
        }

        foreach ($entry->getCrumbs() as $crumb) {
            $crumbs[] = $crumb;
        }

        $crumbs[] = [
            'html' => Cp::elementChipHtml($entry, [
                'class' => 'chromeless',
                'hyperlink' => true,
            ]),
        ];

        return $crumbs;
    }

    public function actionRevisions(): Response
    {
        $this->requireLogin();

        $entry = $this->resolveEntry();

        return $this->asJson(['revisions' => $this->revisionOptions($entry)]);
    }

    public function actionDiff(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireLogin();

        $entry = $this->resolveEntry();
        $entryId = (int) $entry->id;
        $siteId = (int) $entry->siteId;

        $a = $this->request->getRequiredBodyParam('a');
        $b = $this->request->getRequiredBodyParam('b');
        if (! is_string($a) || ! is_string($b)) {
            throw new BadRequestHttpException('Version refs "a" and "b" must be strings.');
        }

        $left = VersionRef::resolve($entryId, $a, $siteId);
        $right = VersionRef::resolve($entryId, $b, $siteId);
        if ($left === null || $right === null) {
            throw new BadRequestHttpException('Could not resolve one or both versions to compare.');
        }

        $diff = (new RevisionDiffService())->diff($left, $right);
        $fingerprint = $this->fingerprint($diff);

        // Reuse a prior comparison of the same A/B pair when its resolved
        // content is unchanged: same narration session, same rendered
        // artifact, and — the whole point — no second LLM run.
        /** @var ?ComparisonRecord $comparison */
        $comparison = ComparisonRecord::findOne([
            'entryId' => $entryId,
            'siteId' => $siteId,
            'aRef' => $a,
            'bRef' => $b,
        ]);
        /** @var ?SessionRecord $session */
        $session = $comparison !== null ? SessionRecord::findOne(['id' => $comparison->sessionId]) : null;

        if ($comparison !== null && $session !== null && $comparison->fingerprint === $fingerprint) {
            /** @var ?ArtifactRecord $artifact */
            $artifact = $comparison->artifactId !== null
                ? ArtifactRecord::findOne(['id' => $comparison->artifactId])
                : null;
            if ($artifact === null) {
                // Session survived but its artifact was pruned — re-render
                // without re-narrating (the narrative still lives on the
                // session and the front end will poll it).
                $artifact = $this->renderArtifact($entry, $diff, (string) $session->id);
                $comparison->artifactId = (int) $artifact->id;
                $comparison->save(false);
            }

            return $this->diffResponse($diff, (string) $session->id, $artifact, reused: true);
        }

        // Fresh comparison, or a mutable side ("current"/a draft) moved since we
        // last narrated it. Render the artifact, (re)bind a session, narrate,
        // and memoize so the next identical open is free.
        $sessionId = $session !== null ? (string) $session->id : $this->createSession($entry);
        $artifact = $this->renderArtifact($entry, $diff, $sessionId);
        $this->narrate($sessionId, $entry, $diff);

        if ($comparison === null) {
            $comparison = new ComparisonRecord();
            $comparison->entryId = $entryId;
            $comparison->siteId = $siteId;
            $comparison->aRef = $a;
            $comparison->bRef = $b;
        }
        $comparison->fingerprint = $fingerprint;
        $comparison->sessionId = $sessionId;
        $comparison->artifactId = (int) $artifact->id;
        $comparison->save(false);

        return $this->diffResponse($diff, $sessionId, $artifact, reused: false);
    }

    /**
     * Render + persist the diff as an HTML artifact owned by the given session.
     *
     * @param  array<string, mixed>  $diff
     */
    private function renderArtifact(Entry $entry, array $diff, string $sessionId): ArtifactRecord
    {
        $heading = sprintf('%s → %s', $this->label($diff, 'a'), $this->label($diff, 'b'));
        $html = (new DiffRenderer())->render($diff, $this->entryTitle($entry).' · '.$heading);

        $artifact = new ArtifactRecord();
        $artifact->sessionId = $sessionId;
        $artifact->entryId = (int) $entry->id;
        $artifact->title = $heading;
        $artifact->html = $html;
        $artifact->mimeType = 'text/html';
        $artifact->save(false);

        return $artifact;
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function diffResponse(array $diff, string $sessionId, ArtifactRecord $artifact, bool $reused): Response
    {
        return $this->asJson([
            'ok' => true,
            // True when this open hit the memo (no new session, no narration).
            'reused' => $reused,
            'sessionId' => $sessionId,
            'artifactId' => (int) $artifact->id,
            'artifactUrl' => UrlHelper::cpUrl('ai/artifacts/'.$artifact->id),
            'html' => $artifact->html,
            'summary' => $diff['summary'],
            'a' => $diff['a'],
            'b' => $diff['b'],
        ]);
    }

    /**
     * Content identity of both resolved sides — each side's ref + dateUpdated.
     * Two immutable revisions yield a constant fingerprint (a permanent cache
     * hit); a "current"/draft side changes its dateUpdated when edited, which
     * misses the memo and forces exactly one re-narration.
     *
     * @param  array<string, mixed>  $diff
     */
    private function fingerprint(array $diff): string
    {
        $side = function (string $key) use ($diff): string {
            $version = is_array($diff[$key] ?? null) ? $diff[$key] : [];
            $ref = is_string($version['ref'] ?? null) ? $version['ref'] : $key;
            $stamp = is_string($version['dateUpdated'] ?? null) ? $version['dateUpdated'] : '';

            return $ref.'@'.$stamp;
        };

        return hash('sha256', $side('a').'|'.$side('b'));
    }

    private function resolveEntry(): Entry
    {
        $rawId = $this->request->getParam('entryId');
        if (! is_numeric($rawId)) {
            throw new BadRequestHttpException('entryId must be numeric.');
        }

        $query = Entry::find()->id((int) $rawId)->status(null);
        $rawSite = $this->request->getParam('siteId');
        if (is_numeric($rawSite)) {
            $query->siteId((int) $rawSite);
        }

        $entry = $query->one();
        if (! $entry instanceof Entry) {
            throw new NotFoundHttpException('Entry not found.');
        }

        return $entry;
    }

    private function refParam(string $name, string $default): string
    {
        $value = $this->request->getParam($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * The current entry, then its open drafts, then each revision newest-first,
     * as picker options — so an editor can compare a work-in-progress draft
     * against the live entry or any past revision. Revision rows mirror the
     * shape {@see \markhuot\craftai\tools\GetRevisions} returns; the "current"
     * row is synthetic.
     *
     * @return list<array<string, mixed>>
     */
    private function revisionOptions(Entry $entry): array
    {
        $current = [
            'ref' => 'current',
            'label' => 'Current',
            'revisionNum' => null,
            'savedBy' => null,
            'dateUpdated' => $entry->dateUpdated?->format(\DateTimeInterface::ATOM),
            'isCurrent' => true,
        ];

        return [$current, ...$this->draftOptions($entry), ...$this->revisionRows($entry)];
    }

    /**
     * The entry's saved drafts, newest-edited first. Provisional drafts (Craft's
     * per-user autosave scratch space behind the entry-edit screen) are excluded
     * — they're transient editing state, not versions a user would deliberately
     * line up for comparison.
     *
     * @return list<array<string, mixed>>
     */
    private function draftOptions(Entry $entry): array
    {
        $query = Entry::find()->draftOf($entry->id)->provisionalDrafts(false)->status(null);
        if ($entry->siteId !== null) {
            $query->siteId($entry->siteId);
        }

        $rows = [];
        foreach ($query->all() as $draft) {
            $behavior = VersionRef::draftBehavior($draft);
            $rows[] = [
                'ref' => VersionRef::refFor($draft),
                'label' => VersionRef::label($draft),
                'revisionNum' => null,
                'savedBy' => $behavior?->getCreator()?->username,
                'dateUpdated' => $draft->dateUpdated?->format(\DateTimeInterface::ATOM),
                'isCurrent' => false,
            ];
        }
        usort($rows, static fn (array $x, array $y): int => strcmp(
            is_string($y['dateUpdated'] ?? null) ? $y['dateUpdated'] : '',
            is_string($x['dateUpdated'] ?? null) ? $x['dateUpdated'] : '',
        ));

        return $rows;
    }

    /**
     * The entry's revisions, newest-first.
     *
     * @return list<array<string, mixed>>
     */
    private function revisionRows(Entry $entry): array
    {
        $query = Entry::find()->revisionOf($entry->id)->status(null);
        if ($entry->siteId !== null) {
            $query->siteId($entry->siteId);
        }

        $rows = [];
        foreach ($query->all() as $revision) {
            $behavior = VersionRef::revisionBehavior($revision);
            $rows[] = [
                'ref' => VersionRef::refFor($revision),
                'label' => VersionRef::label($revision),
                'revisionNum' => $behavior?->revisionNum,
                'savedBy' => $behavior?->getCreator()?->username,
                'dateCreated' => $revision->dateCreated?->format(\DateTimeInterface::ATOM),
                'isCurrent' => false,
            ];
        }
        usort($rows, static fn (array $x, array $y): int => ($y['revisionNum'] ?? 0) <=> ($x['revisionNum'] ?? 0));

        return $rows;
    }

    /**
     * Mint the read-only narration session for a comparison.
     *
     * Created with `userId = null` on purpose: a comparison's narrative is
     * about the entry's revisions, not a particular user, and the codebase
     * treats null-owner sessions as "any owner" (see {@see MessagesController},
     * {@see ArtifactsController}). So a second editor who opens the same
     * comparison reuses this session and its artifact — no new session, no
     * second LLM run. The narration job itself is still pushed with the
     * triggering user's id (see {@see narrate}), so tool permission checks run
     * as that user. These rows are background memo state rather than chat
     * sessions a user manages, so keeping them off the per-user session list
     * (which filters by userId) is desirable too.
     */
    private function createSession(Entry $entry): string
    {
        $session = new SessionRecord();
        $session->id = StringHelper::UUID();
        $session->active = false;
        $session->stopRequested = false;
        $session->userId = null;
        $session->toolMode = 'readonly';
        $session->clientType = 'cp';
        $session->title = sprintf('Compare: %s', $this->entryTitle($entry));
        $session->save(false);

        return (string) $session->id;
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function narrate(string $sessionId, Entry $entry, array $diff): void
    {
        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);

        $loop->appendSystemContext($sessionId, $this->buildNarrationNote($entry, $diff));
        $loop->appendUserMessage(
            $sessionId,
            'Summarize what changed between these two revisions. Describe the semantics of each change — what content, values, or relationships differ — grouped by field, leading with the most significant changes. Be concise. Do not provide editorial review: do not judge whether a change is good or bad, intentional or accidental, an improvement or a regression, and do not make recommendations. Just report what changed.',
        );

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userId' => $this->currentUserId(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function buildNarrationNote(Entry $entry, array $diff): string
    {
        $fields = is_array($diff['fields'] ?? null) ? $diff['fields'] : [];

        $lines = [];
        $lines[] = '<ai-compare-revisions>';
        $lines[] = sprintf(
            'The editor is comparing two versions of entry #%d ("%s") in the Craft control panel.',
            (int) $entry->id,
            $this->entryTitle($entry),
        );
        $lines[] = sprintf('Version A: %s. Version B: %s.', $this->label($diff, 'a'), $this->label($diff, 'b'));
        $lines[] = '';
        $lines[] = 'Field-by-field changes (computed deterministically — this list is authoritative):';

        $any = false;
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $status = is_string($field['status'] ?? null) ? $field['status'] : 'unchanged';
            if ($status === 'unchanged') {
                continue;
            }
            $any = true;
            $name = is_string($field['name'] ?? null) && $field['name'] !== ''
                ? $field['name']
                : (is_string($field['handle'] ?? null) ? $field['handle'] : 'field');
            $lines[] = sprintf('  - %s: %s%s', $name, $status, $this->changeHint($field));
        }
        if (! $any) {
            $lines[] = '  (no field-level changes)';
        }

        $lines[] = '';
        $lines[] = 'Describe the semantics of what changed, grouped by field — what content, values, or relationships differ. The change list above is authoritative — only call a read tool if you need fuller content. Do not provide editorial review: no quality judgments, no guesses at intent, no recommendations. Just report what changed. Keep it concise.';
        $lines[] = '</ai-compare-revisions>';

        return implode("\n", $lines);
    }

    /**
     * @param  array<array-key, mixed>  $field
     */
    private function changeHint(array $field): string
    {
        $kind = is_string($field['kind'] ?? null) ? $field['kind'] : '';
        $detail = is_array($field['detail'] ?? null) ? $field['detail'] : [];

        if ($kind === 'relation') {
            $added = is_array($detail['added'] ?? null) ? count($detail['added']) : 0;
            $removed = is_array($detail['removed'] ?? null) ? count($detail['removed']) : 0;

            return " (+{$added} / -{$removed} related)";
        }
        if ($kind === 'matrix') {
            $blocks = is_array($detail['blocks'] ?? null) ? count($detail['blocks']) : 0;

            return " ({$blocks} block change(s))";
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $diff
     */
    private function label(array $diff, string $side): string
    {
        $version = is_array($diff[$side] ?? null) ? $diff[$side] : [];

        return is_string($version['label'] ?? null) ? $version['label'] : strtoupper($side);
    }

    private function entryTitle(Entry $entry): string
    {
        $title = $entry->title;

        return is_string($title) && $title !== '' ? $title : ('#'.$entry->id);
    }

    private function currentUserId(): ?int
    {
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity !== null ? (int) $identity->id : null;
    }
}
