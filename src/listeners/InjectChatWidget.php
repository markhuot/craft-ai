<?php

namespace markhuot\craftai\listeners;

use Craft;
use craft\base\ElementInterface;
use craft\events\TemplateEvent;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use markhuot\craftai\agent\PageContextSerializer;

/**
 * Append the chat widget to a rendered page. Originally a front-end-only
 * thing, but the CP needs the same affordance so editors can prompt
 * "review this" while looking at a draft — and have the page context
 * carry through to the LLM the same way the front-end widget already does
 * for site visitors. We hook the post-render event (rather than {% hook %}
 * or EVENT_END_BODY) so the widget appears on every response without
 * requiring template authors to opt in.
 *
 * Skipped on the CP chat surfaces themselves so we don't double-render a
 * floating chat on top of the full-page one.
 *
 * This listener is registered for two View events on a single shared
 * instance: {@see self::captureTemplate} on EVENT_BEFORE_RENDER_PAGE_TEMPLATE
 * stashes the template name (Craft doesn't pass it through to the
 * after-render event), and {@see self::__invoke} on
 * EVENT_AFTER_RENDER_PAGE_TEMPLATE does the actual injection.
 */
class InjectChatWidget
{
    /**
     * Captured by EVENT_BEFORE_RENDER_PAGE_TEMPLATE so the after-render hook
     * (which is what injects the widget) knows which template produced the
     * page. Craft doesn't pass the template name through to the after-render
     * event, so we have to stash it ourselves.
     */
    private ?string $lastRenderedTemplate = null;

    public function captureTemplate(TemplateEvent $event): void
    {
        if ($event->templateMode === View::TEMPLATE_MODE_SITE) {
            $this->lastRenderedTemplate = $event->template;
        }
    }

    public function __invoke(TemplateEvent $event): void
    {
        $request = Craft::$app->getRequest();
        if (! $request instanceof \craft\web\Request) {
            return;
        }
        if ($request->getIsAjax()) {
            return;
        }

        $isCp = $event->templateMode === View::TEMPLATE_MODE_CP;
        $isSite = $event->templateMode === View::TEMPLATE_MODE_SITE;
        if (! $isCp && ! $isSite) {
            return;
        }

        $user = Craft::$app->getUser();
        if ($user->getIsGuest()) {
            return;
        }

        if ($isSite) {
            // Site templates serve the public — gate by CP access so we
            // don't expose the widget to anonymous-but-logged-in members.
            if (! $user->checkPermission('accessCp')) {
                return;
            }
        } else {
            // CP page that IS the chat. The widget would float on top of the
            // full-page chat — suppress to avoid duplicate UI. The single-
            // session view path is `ai/session/<uuid>`; the index is
            // `ai/sessions`. Both start with `ai/session`.
            $path = (string) $request->getPathInfo();
            if (str_starts_with($path, 'ai/session')) {
                return;
            }
        }

        if ($event->output === '') {
            return;
        }

        $assetManager = Craft::$app->getAssetManager();
        $sourcePath = dirname(__DIR__).'/web/assets/widget/dist';

        try {
            $published = $assetManager->publish($sourcePath);
        } catch (\Throwable) {
            // Source dir is missing in dev before the bundle has been built.
            // Fail closed so the front-end isn't broken by a missing asset.
            return;
        }

        $baseUrl = $published[1] ?? null;
        if (! is_string($baseUrl) || $baseUrl === '') {
            return;
        }

        $context = $this->gatherPageContext($request);

        $jsUrl = $baseUrl.'/widget.js';
        $bootstrap = [
            'jsUrl' => $jsUrl,
            'cssUrl' => $baseUrl.'/widget.css',
            'sessionsUrl' => UrlHelper::actionUrl('craft-ai/sessions/data'),
            'newSessionUrl' => UrlHelper::actionUrl('craft-ai/sessions/new'),
            'sessionsIndexUrl' => UrlHelper::cpUrl('ai/sessions'),
            'messagesUrl' => UrlHelper::actionUrl('craft-ai/messages'),
            'sendUrl' => UrlHelper::actionUrl('craft-ai/sessions/send'),
            // Front-end widget never receives a previewRequest (tools are CP-only),
            // but we ship the URL anyway so the shared Chat component can stay
            // bootstrap-agnostic and we don't fork the type for a single use case.
            'previewRespondUrl' => UrlHelper::actionUrl('craft-ai/preview/respond'),
            'toolModeUrl' => UrlHelper::actionUrl('craft-ai/sessions/tool-mode'),
            'updateToolModeUrl' => UrlHelper::actionUrl('craft-ai/sessions/update-tool-mode'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => $request->getCsrfToken(),
            'context' => $context,
            'contextFingerprint' => PageContextSerializer::fingerprint($context),
            'contextWindow' => \markhuot\craftai\Plugin::getInstance()->getContextWindow(),
            // Where the in-widget "Leave a comment" composer (opened
            // from the CKEditor toolbar plugin) POSTs the user's
            // authored body. Mirrors the URL the comments overlay JS
            // reads — keeping the widget self-contained means the
            // composer doesn't have to reach across to the overlay's
            // bootstrap script tag at submit time.
            'commentsCreateUrl' => UrlHelper::actionUrl('craft-ai/comments/create'),
            // Used by the comment composer to enrich the attachment
            // chips after the asset picker resolves. Same endpoint the
            // dedicated session view uses — sharing keeps the widget
            // and the standalone chat in sync if the route is ever
            // renamed.
            'assetsInfoUrl' => UrlHelper::actionUrl('craft-ai/assets/info'),
        ];

        $bootstrapJson = Json::htmlEncode($bootstrap);

        $snippet = <<<HTML
<div data-craftai-widget-host></div>
<script type="application/json" data-craftai-widget-bootstrap>{$bootstrapJson}</script>
<script type="module" src="{$jsUrl}"></script>
HTML;

        if (str_contains($event->output, '</body>')) {
            $event->output = (string) preg_replace(
                '/<\/body>/i',
                $snippet."\n</body>",
                $event->output,
                1,
            );

            return;
        }

        $event->output .= $snippet;
    }

    /**
     * Collect a small, JSON-safe snapshot of the page being rendered so the
     * widget can attach it to the next user message when relevant. Stays
     * minimal on purpose — the agent can call tools to look up anything
     * deeper (custom fields, related elements, etc.) once it knows the IDs.
     *
     * Front-end pages: the matched element comes from the URL manager's
     * routed element. CP pages: there is no matched element (controller
     * routes don't run that path), so we recover one by inspecting the
     * route params Craft's CP route table populates — `elementId` is the
     * common name across entry/asset/category/user edit pages — and look
     * the element up in the DB. `draftId` (from the query string) is
     * honored when present so a draft edit page resolves to the actual
     * draft, not its canonical.
     *
     * @return array{surface: string, url: ?string, path: ?string, query: array<string, mixed>, siteHandle: ?string, template: ?string, element: ?array{type: string, id: int, title: ?string, sectionHandle: ?string, isDraft: bool, draftId: ?int, canonicalId: ?int}}
     */
    private function gatherPageContext(\craft\web\Request $request): array
    {
        $url = $this->safeAbsoluteUrl($request);
        $path = $request->getPathInfo();
        $isCp = $request->getIsCpRequest();

        /** @var array<string, mixed> $rawQuery */
        $rawQuery = $request->getQueryParams();
        $query = $this->scalarizeQuery($rawQuery);

        $siteHandle = null;
        try {
            $site = Craft::$app->getSites()->getCurrentSite();
            $siteHandle = $site->handle;
        } catch (\Throwable) {
            // currentSite isn't always available outside of a request — fall through.
        }

        $element = $isCp
            ? $this->resolveCpRouteElement($request)
            : $this->resolveSiteRouteElement();

        return [
            'surface' => $isCp ? 'cp' : 'site',
            'url' => $url,
            'path' => $path !== '' ? $path : null,
            'query' => $query,
            'siteHandle' => $siteHandle,
            'template' => $this->lastRenderedTemplate,
            'element' => $element,
        ];
    }

    /**
     * @return array{type: string, id: int, title: ?string, sectionHandle: ?string, isDraft: bool, draftId: ?int, canonicalId: ?int}|null
     */
    private function resolveSiteRouteElement(): ?array
    {
        try {
            $matched = Craft::$app->getUrlManager()->getMatchedElement();
            if ($matched instanceof ElementInterface) {
                return $this->summarizeElement($matched);
            }
        } catch (\Throwable) {
            // Some plugins or routes resolve outside the URL manager — ignore.
        }
        return null;
    }

    /**
     * Look at the CP route params + query string to recover the element being
     * edited. Craft's bundled CP routes all funnel through `elements/edit`
     * with an `elementId` route param, so reading that one key covers entry /
     * asset / category edit pages (and any third-party plugin that follows
     * the same convention). Draft edits add `?draftId=X` to the URL — we
     * prefer the draft element when present so the widget reports the user's
     * actual working copy.
     *
     * @return array{type: string, id: int, title: ?string, sectionHandle: ?string, isDraft: bool, draftId: ?int, canonicalId: ?int}|null
     */
    private function resolveCpRouteElement(\craft\web\Request $request): ?array
    {
        try {
            /** @var array<string, mixed> $routeParams */
            $routeParams = Craft::$app->getUrlManager()->getRouteParams();
        } catch (\Throwable) {
            $routeParams = [];
        }

        $elementId = $this->intOrNull($routeParams['elementId'] ?? null);
        // Some CP routes name the param differently — `userId` for user
        // edit pages on team/pro editions. Fall back to those in priority
        // order rather than walking the whole array, so we don't grab a
        // numeric value (like `siteId`) that doesn't represent an element.
        if ($elementId === null) {
            $elementId = $this->intOrNull($routeParams['userId'] ?? null);
        }
        if ($elementId === null) {
            return null;
        }

        $draftId = $this->intOrNull($request->getQueryParam('draftId'));
        $siteId = $this->intOrNull($request->getQueryParam('siteId'))
            ?? $this->intOrNull($routeParams['siteId'] ?? null);

        try {
            // Canonical first — gives us the element class, which we then
            // use to query for the draft. (draftId in the URL refers to
            // the drafts-table row, not the elements-table id, so a
            // plain getElementById($draftId) would miss.)
            $canonical = Craft::$app->getElements()->getElementById($elementId, ElementInterface::class, $siteId);
            if (! $canonical instanceof ElementInterface) {
                return null;
            }

            if ($draftId !== null) {
                $class = $canonical::class;
                $query = $class::find();
                $query->draftId($draftId);
                // null = include both provisional and non-provisional
                // drafts (provisionalDrafts(true) would exclude the
                // explicit-save ones).
                $query->provisionalDrafts(null);
                if ($siteId !== null) {
                    $query->siteId($siteId);
                }
                $query->status(null);
                $draft = $query->one();
                if ($draft instanceof ElementInterface) {
                    return $this->summarizeElement($draft);
                }
            }

            return $this->summarizeElement($canonical);
        } catch (\Throwable) {
            // Element lookups can throw if the table is missing or the id
            // points at a deleted row — fall through so the widget still
            // gets a page context, just without an element.
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }
        return null;
    }

    private function safeAbsoluteUrl(\craft\web\Request $request): ?string
    {
        try {
            $url = $request->getAbsoluteUrl();
            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Drop anything that can't round-trip cleanly through JSON (resources,
     * objects, etc.) so the bootstrap stays a flat scalar map.
     *
     * @param array<array-key, mixed> $params
     * @return array<string, string|int|float|bool|null>
     */
    private function scalarizeQuery(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * @return array{type: string, id: int, title: ?string, sectionHandle: ?string, isDraft: bool, draftId: ?int, canonicalId: ?int}
     */
    private function summarizeElement(\craft\base\ElementInterface $element): array
    {
        $type = $element::refHandle();
        if (! is_string($type) || $type === '') {
            $type = strtolower((new \ReflectionClass($element))->getShortName());
        }

        $sectionHandle = null;
        if ($element instanceof \craft\elements\Entry) {
            try {
                $section = $element->getSection();
                $sectionHandle = $section?->handle;
            } catch (\Throwable) {
                // No section (e.g. nested entries inside Matrix) — leave null.
            }
        } elseif ($element instanceof \craft\elements\Category) {
            try {
                $sectionHandle = $element->getGroup()->handle;
            } catch (\Throwable) {
                // Group lookup can fail when categories are queried out of context.
            }
        }

        $title = (string) $element->getUiLabel();
        if ($title === '') {
            $title = $element->title ?? null;
        }

        // Draft/canonical info lets the agent pick the right tool
        // (`get_draft` vs `get_entry`) without having to guess from the type.
        // Non-draftable elements (assets, users, etc.) always report
        // isDraft=false and skip the id pair.
        $isDraft = false;
        $draftId = null;
        $canonicalId = null;
        if ($element->getIsDraft()) {
            $isDraft = true;
            $draftId = isset($element->draftId) ? (int) $element->draftId : null;
            $canonicalId = isset($element->canonicalId) ? (int) $element->canonicalId : null;
        }

        return [
            'type' => $type,
            'id' => (int) $element->id,
            'title' => is_string($title) && $title !== '' ? $title : null,
            'sectionHandle' => $sectionHandle,
            'isDraft' => $isDraft,
            'draftId' => $draftId,
            'canonicalId' => $canonicalId,
        ];
    }
}
