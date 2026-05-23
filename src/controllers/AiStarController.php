<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\SessionRecord;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * HTTP surface for the per-field "AI fill" star.
 *
 * The CP overlay decorates every custom field on an element edit screen with a
 * small star button. Clicking the star posts to this controller, which spins
 * up a fresh session pre-seeded with the page context (which element/draft the
 * editor is on) and a directive asking the agent to propose a value for the
 * targeted field. The widget then opens against the new session via the
 * existing `craftai:open-session` event, so the editor watches the agent
 * orchestrate the fill without leaving the page.
 *
 * Supports every Craft element type Craft ships with — entries (including
 * drafts and Matrix-nested entries), assets, categories, users — by routing
 * the agent to the matching `get_*` / `upsert_*` tool pair in the system
 * prelude. Orchestration runs on the queue worker (just like the regular
 * send endpoint), so the browser's job is purely to receive the new session
 * id — if the user closes the tab mid-run the agent still finishes.
 */
class AiStarController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    /**
     * POST craft-ai/ai-star/fill-field
     *
     * Body params:
     *   - elementId   (int, required): canonical element id or draftId.
     *   - isDraft     (0|1, optional, default 0): whether elementId is a draftId.
     *                 Only meaningful for Entry; ignored for other element types.
     *   - fieldHandle (string, required): the handle of the field to fill.
     *   - fieldLabel  (string, optional): human-readable field label,
     *                 surfaced in the system note for the agent.
     *   - blockElementId (int, optional): for matrix-nested fields, the
     *                 block's element id (used to scope the fill to the
     *                 right inner entry).
     *   - blockTypeHandle (string, optional): matrix block type handle —
     *                 belt-and-braces context so the agent knows which
     *                 entry-type schema to consult.
     *   - siteId      (int, optional): id of the Craft site the editor is
     *                 currently viewing the element on. Read from the
     *                 edit form's hidden `siteId` input. When provided we
     *                 surface the site's handle + language in the system
     *                 note so the agent calls the get and upsert tools
     *                 with the right `site` argument from its first tool
     *                 call — without this hint the agent reads the
     *                 element on the install's primary site and has to
     *                 discover the actual locale by trial-and-error.
     */
    public function actionFillField(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $elementIdRaw = $this->request->getRequiredBodyParam('elementId');
        if (! is_numeric($elementIdRaw)) {
            throw new BadRequestHttpException('elementId must be numeric.');
        }
        $elementId = (int) $elementIdRaw;
        $isDraft = (bool) $this->request->getBodyParam('isDraft', false);

        $fieldHandle = $this->request->getRequiredBodyParam('fieldHandle');
        if (! is_string($fieldHandle) || $fieldHandle === '') {
            throw new BadRequestHttpException('fieldHandle must be a non-empty string.');
        }

        $fieldLabelRaw = $this->request->getBodyParam('fieldLabel');
        $fieldLabel = is_string($fieldLabelRaw) && $fieldLabelRaw !== '' ? $fieldLabelRaw : null;

        $blockElementIdRaw = $this->request->getBodyParam('blockElementId');
        $blockElementId = is_numeric($blockElementIdRaw) ? (int) $blockElementIdRaw : null;
        $blockTypeHandleRaw = $this->request->getBodyParam('blockTypeHandle');
        $blockTypeHandle = is_string($blockTypeHandleRaw) && $blockTypeHandleRaw !== ''
            ? $blockTypeHandleRaw
            : null;

        $siteIdRaw = $this->request->getBodyParam('siteId');
        $siteId = is_numeric($siteIdRaw) ? (int) $siteIdRaw : null;
        // Resolve the site server-side so a fabricated id from the browser
        // doesn't end up in the system note. Unknown ids fall through to
        // null, which the prompt builder handles by simply omitting the
        // site stanza (same shape as a single-site install).
        $site = $siteId !== null
            ? Craft::$app->getSites()->getSiteById($siteId)
            : null;

        // Confirm the target element exists before minting a session — we
        // don't want an orphan SessionRecord hanging around if the page's
        // hidden inputs lie about the element id. Lookup is element-class
        // agnostic so the star works on assets / categories / users in
        // addition to entries; drafts are still entry-only (Craft's draft
        // system is entry-scoped).
        //
        // Pass through the site so the resolved element matches the
        // locale the editor is viewing — otherwise `getUiLabel()` reads
        // off the primary site and the system note's display name
        // disagrees with what the editor sees on screen.
        $element = $this->resolveElement($elementId, $isDraft, $site);
        if ($element === null) {
            throw new NotFoundHttpException(sprintf(
                'No %s found with id %d.',
                $isDraft ? 'draft' : 'element',
                $elementId,
            ));
        }

        $userId = Craft::$app->getUser()->getId();
        $userIdInt = is_numeric($userId) ? (int) $userId : null;

        $session = new SessionRecord();
        $session->id = StringHelper::UUID();
        $session->active = false;
        $session->stopRequested = false;
        $session->userId = $userIdInt;
        $session->toolMode = 'full';
        $session->clientType = 'cp';
        $session->title = sprintf('Fill field: %s', $fieldLabel ?? $fieldHandle);
        if (! $session->save()) {
            throw new BadRequestHttpException(
                'Could not create fill-field session: '.implode('; ', array_map(
                    static fn ($errors): string => implode(', ', (array) $errors),
                    $session->getErrors(),
                )),
            );
        }
        $sessionId = (string) $session->id;

        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);

        // System note pinning the element + field context. The agent's
        // first turn sees this alongside the user message and decides
        // which tools to call.
        $systemNote = $this->buildSystemNote(
            element: $element,
            elementId: $elementId,
            isDraft: $isDraft,
            fieldHandle: $fieldHandle,
            fieldLabel: $fieldLabel,
            blockElementId: $blockElementId,
            blockTypeHandle: $blockTypeHandle,
            site: $site,
        );
        $loop->appendSystemContext($sessionId, $systemNote);

        // User-role directive. Kept short and instruction-shaped so the
        // agent can immediately call its lookup tools without parsing a
        // wall of prose. The "why" lives in the system note above.
        $userMessage = $this->buildUserMessage(
            element: $element,
            fieldHandle: $fieldHandle,
            fieldLabel: $fieldLabel,
            isDraft: $isDraft,
            blockElementId: $blockElementId,
        );
        $loop->appendUserMessage($sessionId, $userMessage);

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userId' => $userIdInt,
        ]));

        return $this->asJson([
            'ok' => true,
            'sessionId' => $sessionId,
            'sessionUrl' => UrlHelper::cpUrl('ai/session/'.$sessionId),
        ]);
    }

    /**
     * Locate the target element by id, optionally scoped to a specific
     * site so the loaded values match the locale the editor is viewing.
     *
     * For drafts we have to go through `Entry::find()->draftId(...)`
     * because the `draftId` is a row id in the drafts table, not the
     * elements table — a plain `getElementById($draftId)` would miss.
     * For canonical lookups we defer to the generic elements service so
     * the same endpoint serves every element type Craft ships with
     * (entries, assets, categories, users, plus anything third-party
     * plugins register).
     */
    private function resolveElement(int $elementId, bool $isDraft, ?Site $site): ?ElementInterface
    {
        if ($isDraft) {
            $query = Entry::find()->draftId($elementId)->status(null);
            if ($site !== null) {
                $query->siteId($site->id);
            }
            $draft = $query->one();
            return $draft instanceof ElementInterface ? $draft : null;
        }

        // Two-step lookup so the elements service can return the concrete
        // class. Passing `ElementInterface::class` straight to
        // `getElementById` looks tempting but trips `class_exists` (the
        // service uses it as a guard) since interfaces aren't classes —
        // the call would silently return null at runtime even for valid
        // ids. Looking up the type first sidesteps that.
        $elementType = Craft::$app->getElements()->getElementTypeById($elementId);
        if ($elementType === null) {
            return null;
        }

        return Craft::$app->getElements()->getElementById(
            $elementId,
            $elementType,
            $site?->id,
        );
    }

    /**
     * Best-effort human label for an element. `getUiLabel` is what Craft's
     * CP chips use, so it matches what the editor sees in the breadcrumb /
     * title bar. Falls back to the raw `title` attribute and finally an
     * empty string when neither yields anything (e.g. a Globalset).
     */
    private function elementDisplayName(ElementInterface $element): string
    {
        $label = $element->getUiLabel();
        if ($label !== '') {
            return $label;
        }
        $title = $element->title ?? null;
        return is_string($title) ? $title : '';
    }

    /**
     * Map a concrete element instance to a (read, write) tool name pair
     * the agent should call. Falls back to the generic `get_entry` /
     * `upsert_entry` flow for unknown types — those tools still work
     * against any Entry-backed element via Craft's elements service.
     *
     * @return array{kind: string, readTool: string, writeTool: string}
     */
    private function toolsForElement(ElementInterface $element): array
    {
        if ($element instanceof Asset) {
            return ['kind' => 'asset', 'readTool' => 'get_asset', 'writeTool' => 'upsert_asset'];
        }
        if ($element instanceof User) {
            // No first-party user upsert tool today — the agent has to fall
            // back to a generic explanation. We still surface the read
            // path so it can summarize what it would have changed.
            return ['kind' => 'user', 'readTool' => 'get_entry', 'writeTool' => 'upsert_entry'];
        }
        if ($element instanceof Category) {
            return ['kind' => 'category', 'readTool' => 'get_entry', 'writeTool' => 'upsert_entry'];
        }
        // Default: Entry. Covers canonical entries, drafts, and Matrix-
        // nested entries — the agent picks `get_draft` / `upsert_draft`
        // from the system note when isDraft is set.
        return ['kind' => 'entry', 'readTool' => 'get_entry', 'writeTool' => 'upsert_entry'];
    }

    private function buildSystemNote(
        ElementInterface $element,
        int $elementId,
        bool $isDraft,
        string $fieldHandle,
        ?string $fieldLabel,
        ?int $blockElementId,
        ?string $blockTypeHandle,
        ?Site $site,
    ): string {
        $tools = $this->toolsForElement($element);
        $kind = $tools['kind'];
        $readTool = $tools['readTool'];
        $writeTool = $tools['writeTool'];

        // Drafts are an Entry-only concept; ignore the flag for other
        // element types so the prompt doesn't tell the agent to call
        // `get_draft` against an asset.
        $effectiveIsDraft = $isDraft && $kind === 'entry';
        $targetLabel = $effectiveIsDraft ? 'draft' : $kind;

        $displayName = $this->elementDisplayName($element);

        $lines = [];
        $lines[] = '<ai-fill-field>';
        $lines[] = 'The editor clicked the "AI fill" star next to a field on a Craft control-panel edit screen.';
        $lines[] = 'Your job: propose and save a value for that specific field, using the rest of the element as source data.';
        $lines[] = '';
        $lines[] = sprintf(
            'Target: %s #%d%s.',
            $targetLabel,
            $elementId,
            $displayName !== '' ? ' ("'.$displayName.'")' : '',
        );
        $lines[] = sprintf(
            'Field: `%s`%s',
            $fieldHandle,
            $fieldLabel !== null ? ' (label: "'.$fieldLabel.'")' : '',
        );
        if ($site !== null) {
            // Surface the editor's current locale so the agent passes
            // `site=<handle>` to its first get_*/upsert_* call instead
            // of reading the element off the install's primary site and
            // then having to backtrack. This is the single biggest cost
            // the AI fill flow paid before — see the rainbow-story
            // session where the agent burned four tool calls and a
            // round-trip just to figure out the editor was on Spanish.
            $lines[] = sprintf(
                'Site: `%s` (language `%s`) — the editor is viewing the %s on this locale. Pass `site: "%s"` to every `%s` / `%s` call so reads and writes target this locale, not the install\'s primary site.',
                $site->handle,
                $site->language,
                $kind,
                $site->handle,
                $readTool,
                $effectiveIsDraft ? 'upsert_draft' : $writeTool,
            );
        }
        if ($blockElementId !== null) {
            $lines[] = sprintf(
                'Matrix block: nested entry #%d%s — the field lives inside this block, not at the top level.',
                $blockElementId,
                $blockTypeHandle !== null ? ' (block type `'.$blockTypeHandle.'`)' : '',
            );
        }
        $lines[] = '';
        $lines[] = 'Workflow:';
        if ($kind === 'entry' && $effectiveIsDraft) {
            $lines[] = '  1. Call `get_draft` to read the draft\'s current values for context.';
            $lines[] = '  2. Decide on a value that fits the field\'s purpose and is consistent with the rest of the draft.';
            $lines[] = '  3. Persist the value with `upsert_draft`, scoped to the field handle above.';
        } else {
            $lines[] = sprintf('  1. Call `%s` to read the %s\'s current values for context.', $readTool, $kind);
            $lines[] = sprintf('  2. Decide on a value that fits the field\'s purpose and is consistent with the rest of the %s.', $kind);
            if ($kind === 'entry') {
                $lines[] = '  3. Persist the value with `upsert_draft` (preferred — never edit a live entry directly without an explicit user confirmation).';
            } else {
                $lines[] = sprintf('  3. Persist the value with `%s`, scoped to the field handle above.', $writeTool);
            }
        }
        $lines[] = '  4. Summarize what you wrote for the editor in plain prose.';
        $lines[] = '</ai-fill-field>';

        return implode("\n", $lines);
    }

    private function buildUserMessage(
        ElementInterface $element,
        string $fieldHandle,
        ?string $fieldLabel,
        bool $isDraft,
        ?int $blockElementId,
    ): string {
        $kind = $this->toolsForElement($element)['kind'];
        $effectiveIsDraft = $isDraft && $kind === 'entry';

        $fieldDisplay = $fieldLabel !== null
            ? sprintf('the "%s" field (handle `%s`)', $fieldLabel, $fieldHandle)
            : sprintf('the `%s` field', $fieldHandle);

        if ($blockElementId !== null) {
            return sprintf(
                'Please fill in %s inside the matrix block I clicked. Read the surrounding entry for context, then save your proposed value to the draft.',
                $fieldDisplay,
            );
        }

        $targetLabel = $effectiveIsDraft ? 'draft' : $kind;
        $persistTarget = $effectiveIsDraft ? 'draft' : $kind;

        return sprintf(
            'Please fill in %s on this %s. Read the existing %s data for context, then save your proposed value to the %s.',
            $fieldDisplay,
            $targetLabel,
            $kind,
            $persistTarget,
        );
    }
}
