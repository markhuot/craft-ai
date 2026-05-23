<?php

namespace markhuot\craftai;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\DraftEvent;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\services\Drafts;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\PageContextSerializer;
use markhuot\craftai\agent\RegisterAgentToolsEvent;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\fields\CodeComponent;
use markhuot\craftai\fields\CodeComponentModule;
use markhuot\craftai\fields\CodeComponentPermissions;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Settings;
use markhuot\craftai\notes\CkeditorFieldNotes;
use markhuot\craftai\permissions\ToolPermissions;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\services\AutomationDispatcher;
use markhuot\craftai\agent\providers\AnthropicProvider;
use markhuot\craftai\agent\providers\BraveSearchProvider;
use markhuot\craftai\agent\providers\DuckDuckGoSearchProvider;
use markhuot\craftai\agent\providers\GeminiImageProvider;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\OpenAiImageProvider;
use markhuot\craftai\agent\providers\OpenAiProvider;
use markhuot\craftai\agent\providers\SearchProvider;
use markhuot\craftai\agent\providers\SearchProviderRegistry;
use markhuot\craftai\tools\ApplyDraft;
use markhuot\craftai\tools\DeleteAssets;
use markhuot\craftai\tools\DeleteDrafts;
use markhuot\craftai\tools\DeleteEntries;
use markhuot\craftai\tools\DeleteEntryTypes;
use markhuot\craftai\tools\DeleteFields;
use markhuot\craftai\tools\DeleteSections;
use markhuot\craftai\tools\FetchWebpage;
use markhuot\craftai\tools\GenerateImageGptImage;
use markhuot\craftai\tools\GenerateImageNanoBanana;
use markhuot\craftai\tools\GetAsset;
use markhuot\craftai\tools\GetAssets;
use markhuot\craftai\tools\GetComments;
use markhuot\craftai\tools\GetDraft;
use markhuot\craftai\tools\LeaveComment;
use markhuot\craftai\tools\ResolveComment;
use markhuot\craftai\tools\GetImage;
use markhuot\craftai\tools\GetPreview;
use markhuot\craftai\tools\GetDrafts;
use markhuot\craftai\tools\GetEntries;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\GetEntryTypes;
use markhuot\craftai\tools\GetFields;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\GetSections;
use markhuot\craftai\tools\GetSites;
use markhuot\craftai\tools\GetTemplate;
use markhuot\craftai\tools\GetTemplates;
use markhuot\craftai\tools\GetVolumes;
use markhuot\craftai\tools\OpenPreview;
use markhuot\craftai\tools\SearchTheWeb;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertAsset;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\RemoveFieldLayoutElement;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftai\tools\UpsertEntryType;
use markhuot\craftai\tools\UpsertField;
use markhuot\craftai\tools\UpsertFieldLayoutElement;
use markhuot\craftai\tools\UpsertSection;
use markhuot\craftai\tools\UpsertSite;
use markhuot\craftai\tools\UpsertTemplate;
use markhuot\craftai\web\assets\ckeditorcomment\CkeditorCommentAsset;
use yii\base\Event;

class Plugin extends BasePlugin
{
    /**
     * @event RegisterAgentToolsEvent Fired after the plugin's built-in
     * agent tools have been registered. Listeners may append entries to
     * `$event->tools`; each will then be added to the shared ToolRegistry.
     * Used by the CodeComponent field (and intended for other Craft
     * plugins) to contribute custom tools without modifying the base
     * plugin.
     */
    public const EVENT_REGISTER_AGENT_TOOLS = 'registerAgentTools';

    public string $schemaVersion = '1.11.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    private ToolRegistry $toolRegistry;

    /**
     * Captured by EVENT_BEFORE_RENDER_PAGE_TEMPLATE so the after-render hook
     * (which is what injects the widget) knows which template produced the
     * page. Craft doesn't pass the template name through to the after-render
     * event, so we have to stash it ourselves.
     */
    private ?string $lastRenderedTemplate = null;

    public static function getInstance(): static
    {
        $instance = parent::getInstance();

        if ($instance === null) {
            throw new \RuntimeException('craft-ai plugin is not installed');
        }

        return $instance;
    }

    public function init(): void
    {
        parent::init();

        $this->toolRegistry = new ToolRegistry();
        $this->toolRegistry->register(GetHealth::class);
        $this->toolRegistry->register(GetEntries::class);
        $this->toolRegistry->register(GetEntry::class);
        $this->toolRegistry->register(UpsertEntry::class);
        $this->toolRegistry->register(GetDraft::class);
        $this->toolRegistry->register(GetDrafts::class);
        $this->toolRegistry->register(UpsertDraft::class);
        $this->toolRegistry->register(ApplyDraft::class);
        $this->toolRegistry->register(GetSections::class);
        $this->toolRegistry->register(UpsertSection::class);
        $this->toolRegistry->register(GetSites::class);
        $this->toolRegistry->register(UpsertSite::class);
        $this->toolRegistry->register(GetEntryTypes::class);
        $this->toolRegistry->register(UpsertEntryType::class);
        $this->toolRegistry->register(GetFields::class);
        $this->toolRegistry->register(UpsertField::class);
        $this->toolRegistry->register(UpsertFieldLayoutElement::class);
        $this->toolRegistry->register(RemoveFieldLayoutElement::class);
        $this->toolRegistry->register(GetTemplates::class);
        $this->toolRegistry->register(GetTemplate::class);
        $this->toolRegistry->register(UpsertTemplate::class);
        $this->toolRegistry->register(GetAsset::class);
        $this->toolRegistry->register(GetAssets::class);
        $this->toolRegistry->register(GetVolumes::class);
        $this->toolRegistry->register(UpsertAsset::class);
        $this->toolRegistry->register(DeleteAssets::class);
        $this->toolRegistry->register(DeleteEntries::class);
        $this->toolRegistry->register(DeleteDrafts::class);
        $this->toolRegistry->register(DeleteSections::class);
        $this->toolRegistry->register(DeleteEntryTypes::class);
        $this->toolRegistry->register(DeleteFields::class);
        $this->toolRegistry->register(FetchWebpage::class, cpOnly: true);
        $this->toolRegistry->register(GetImage::class);
        $this->toolRegistry->register(OpenPreview::class, cpOnly: true);
        $this->toolRegistry->register(GetPreview::class, cpOnly: true);
        // Review-comments tools. The leave/resolve pair is CP-only because
        // their value depends on the CP-side overlay that surfaces the
        // comments next to fields — an MCP client wouldn't have anywhere
        // to render them. get_comments is allowed everywhere so external
        // clients can audit feedback.
        $this->toolRegistry->register(LeaveComment::class, cpOnly: true);
        $this->toolRegistry->register(ResolveComment::class, cpOnly: true);
        $this->toolRegistry->register(GetComments::class);

        $this->registerImageTools();
        $this->registerSearchTools();

        // PoC consumer of the public registration event — also wires the
        // field type into Craft. Doing this *before* firing the event keeps
        // the bundled module on equal footing with any external listener.
        CodeComponentModule::bootstrap();

        $this->dispatchAgentToolRegistration();

        $this->registerContainerBindings();
        $this->registerAutomationListeners();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'markhuot\\craftai\\console\\controllers';
        }

        Event::on(
            UpsertField::class,
            UpsertField::EVENT_DEFINE_FIELD_NOTES,
            new CkeditorFieldNotes(),
        );

        $this->registerCkeditorCommentPlugin();

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event): void {
                $permissions = [];
                foreach ($this->toolRegistry->descriptors() as $descriptor) {
                    $permissions[ToolPermissions::nameForDescriptor($descriptor)] = [
                        'label' => Craft::t('craft-ai', 'Use tool: {name}', ['name' => $descriptor->name]),
                        'info' => $descriptor->description !== '' ? $descriptor->description : null,
                    ];
                }

                foreach (CodeComponentPermissions::definitions() as $definition) {
                    $permissions[$definition['key']] = [
                        'label' => Craft::t('craft-ai', $definition['label']),
                        'info' => Craft::t('craft-ai', $definition['info']),
                    ];
                }

                $event->permissions[] = [
                    'heading' => Craft::t('craft-ai', 'Craft AI'),
                    'permissions' => $permissions,
                ];
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['ai/sessions'] = 'craft-ai/sessions/index';
                $event->rules['ai/sessions/data'] = 'craft-ai/sessions/data';
                $event->rules['POST ai/sessions/install-config'] = 'craft-ai/sessions/install-config';
                $event->rules['POST ai/sessions/new'] = 'craft-ai/sessions/new';
                $event->rules['POST ai/sessions/delete'] = 'craft-ai/sessions/delete';
                $event->rules['POST ai/sessions/stop'] = 'craft-ai/sessions/stop';
                $event->rules['POST ai/preview/respond'] = 'craft-ai/preview/respond';
                $event->rules['ai/session/<uuid:[A-Za-z0-9\-]+>'] = 'craft-ai/sessions/view';

                // Review-comments endpoints. Lookup runs on entry edit
                // page load, resolve/open-thread when the user interacts
                // with the popover. All scoped under `ai/comments/*` so a
                // host site can disable them with a single rule override.
                // `open-thread` lazily forks the comment's originating
                // session so the discussion in the chat widget stays
                // isolated from the main agent run.
                $event->rules['ai/comments'] = 'craft-ai/comments/index';
                $event->rules['POST ai/comments/resolve'] = 'craft-ai/comments/resolve';
                $event->rules['POST ai/comments/open-thread'] = 'craft-ai/comments/open-thread';
                // User-initiated span comments from the CKEditor toolbar
                // plugin land here. The endpoint mints a fresh session
                // (so the comment owns its own discussion thread the
                // same way agent-created ones do) and returns the new
                // comment payload to the editor JS for span wrapping.
                $event->rules['POST ai/comments/create'] = 'craft-ai/comments/create';

                // Per-field "AI fill" star. The CP overlay decorates
                // every field on an entry edit screen with a star
                // button — clicking it POSTs here to spin up a fresh
                // session pre-seeded with element + field context, and
                // the widget opens against the returned session id so
                // the editor watches the agent fill the field live.
                $event->rules['POST ai/ai-star/fill-field'] = 'craft-ai/ai-star/fill-field';

                // Dedicated edit screen for a single slash command. The
                // plugin settings page links here from each row in its
                // (read-only) commands list, because a slash-command
                // prompt can grow longer than a settings-table cell
                // comfortably renders. UID is constrained to a UUID
                // shape so the route doesn't shadow `new`.
                $event->rules['ai/commands/new'] = 'craft-ai/commands/edit';
                // Pattern is broader than just a UUID so it also matches the
                // hardcoded UIDs on seeded defaults (see Command::defaults).
                // `new` is registered above so it short-circuits this rule.
                $event->rules['ai/commands/<uid:[A-Za-z0-9\-]+>'] = 'craft-ai/commands/edit';
                $event->rules['POST ai/commands/save'] = 'craft-ai/commands/save';
                $event->rules['POST ai/commands/delete'] = 'craft-ai/commands/delete';

                // Automation rules: dedicated edit screen mirrors the
                // slash-command flow above. Same `new`-first ordering so
                // the literal route short-circuits the parameterized one.
                $event->rules['ai/automations/new'] = 'craft-ai/automations/edit';
                $event->rules['ai/automations/<uid:[A-Za-z0-9\-]+>'] = 'craft-ai/automations/edit';
                $event->rules['POST ai/automations/save'] = 'craft-ai/automations/save';
                $event->rules['POST ai/automations/delete'] = 'craft-ai/automations/delete';
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['POST mcp'] = 'craft-ai/mcp/handle';
                $event->rules['GET mcp'] = 'craft-ai/mcp/handle';
                $event->rules['DELETE mcp'] = 'craft-ai/mcp/handle';
                $event->rules['OPTIONS mcp'] = 'craft-ai/mcp/handle';

                $event->rules['GET .well-known/oauth-authorization-server'] = 'craft-ai/oauth/authorization-server-metadata';
                $event->rules['GET .well-known/oauth-authorization-server/<resourcePath:.*>'] = 'craft-ai/oauth/authorization-server-metadata';
                $event->rules['GET .well-known/oauth-protected-resource'] = 'craft-ai/oauth/protected-resource-metadata';
                $event->rules['GET .well-known/oauth-protected-resource/<resourcePath:.*>'] = 'craft-ai/oauth/protected-resource-metadata';
                $event->rules['POST craft-ai/oauth/register'] = 'craft-ai/oauth/register';
                $event->rules['GET craft-ai/oauth/authorize'] = 'craft-ai/oauth/authorize';
                $event->rules['POST craft-ai/oauth/authorize'] = 'craft-ai/oauth/approve';
                $event->rules['POST craft-ai/oauth/token'] = 'craft-ai/oauth/token';
            },
        );

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event): void {
                if ($event->templateMode === View::TEMPLATE_MODE_SITE) {
                    $this->lastRenderedTemplate = $event->template;
                }
            },
        );

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event): void {
                $this->maybeInjectWidget($event);
                $this->maybeInjectCommentsOverlay($event);
                $this->maybeInjectAiStarOverlay($event);
            },
        );
    }

    /**
     * Wire the CKEditor "Comment" toolbar plugin when the host site has
     * craftcms/ckeditor installed. The plugin ships as a CKEditor 5
     * package bundle (registered via the official
     * `Plugin::registerCkeditorPackage()` entrypoint) plus an HTML
     * Purifier extension so the `<span data-craft-ai-comment-id="…">`
     * marker the plugin writes survives the field's save-time sanitizer.
     *
     * Both hooks are conditional on the CKEditor classes being loadable
     * — installations without the optional dependency skip out entirely
     * and pay no init-time cost. We don't try to soft-depend at the
     * composer level because the existing `CkeditorFieldNotes` listener
     * already follows the same pattern (it checks `instanceof
     * \craft\ckeditor\Field` at fire time).
     */
    private function registerCkeditorCommentPlugin(): void
    {
        if (! class_exists(\craft\ckeditor\Plugin::class)) {
            return;
        }

        // The second arg is the entry JS file name relative to the
        // bundle's sourcePath. CKEditor 5's import-map loader fetches
        // this file when something imports our `$namespace`. The static
        // registration here drives the CkeditorConfig side — the
        // EVENT_AFTER_REGISTER_ASSET_BUNDLE listener inside
        // craft\ckeditor's init reads `$ckeditorPackages` whenever the
        // main CkeditorAsset registers, so it runs late enough that we
        // don't have a load-order problem there.
        \craft\ckeditor\Plugin::registerCkeditorPackage(
            CkeditorCommentAsset::class,
            'ckeditor-comment.js',
        );

        // …but the import-MAP entry (which is what makes
        // `import { CraftAiComment } from "@markhuot/craft-ai-comment"`
        // resolve to a real URL in the browser) gets registered inside
        // craft\ckeditor's own init() — *once*, by iterating
        // `$ckeditorImports` at boot. Plugin handles sort alphabetically
        // and `ckeditor` runs before `craft-ai`, so by the time our
        // init calls `registerCkeditorPackage` above, ckeditor's loop
        // has already consumed an empty array and the browser ends up
        // with no mapping for our namespace ("Module name … does not
        // resolve to a valid URL"). Wire the import ourselves on the
        // same event ckeditor itself uses for the rest of the package
        // registration so the order stops mattering.
        Event::on(
            \craft\web\View::class,
            \craft\web\View::EVENT_AFTER_REGISTER_ASSET_BUNDLE,
            static function (\craft\events\AssetBundleEvent $event): void {
                if (! $event->bundle instanceof \craft\ckeditor\web\assets\ckeditor\CkeditorAsset) {
                    return;
                }
                /** @var \craft\web\View $view */
                $view = $event->sender;
                $assetManager = $view->getAssetManager();

                // `getBundle` instantiates without registering, which is
                // what we want here — we only need the published URL
                // for the import map. The bundle's own `registerPackage`
                // (and CSS / module-script tag) come from ckeditor's
                // listener that fires alongside this one.
                $bundle = $assetManager->getBundle(CkeditorCommentAsset::class);
                if (! $bundle instanceof CkeditorCommentAsset) {
                    return;
                }

                $view->registerJsImport(
                    $bundle->namespace,
                    $assetManager->getAssetUrl($bundle, 'ckeditor-comment.js', false),
                );
            },
        );

        Event::on(
            \craft\ckeditor\Field::class,
            \craft\ckeditor\Field::EVENT_MODIFY_PURIFIER_CONFIG,
            static function (\craft\htmlfield\events\ModifyPurifierConfigEvent $event): void {
                $config = $event->config;

                /** @var \HTMLPurifier_HTMLDefinition|null $def */
                $def = $config->getDefinition('HTML', true);
                if ($def === null) {
                    return;
                }

                // Allow our marker attribute on every `<span>` Craft
                // already permits. The value is a UUID — HTMLPurifier's
                // `Text` matches what we want (a token-like string) and
                // keeps anything weird from sneaking through. We also
                // need `class` on span so the editor's downcast can tag
                // the marker for styling without GeneralHtmlSupport
                // wiping the class off on round-trip.
                $def->addAttribute('span', 'data-craft-ai-comment-id', 'Text');
                $def->addAttribute('span', 'class', 'Text');
            },
        );
    }

    /**
     * Append the AI review-comments overlay to every CP page response.
     *
     * The bundle's TS short-circuits if the page lacks an `elementId` /
     * `draftId` hidden input, so registering globally is cheaper than
     * detecting "is this an entry edit URL" server-side and getting it
     * wrong on third-party plugin routes. The overlay reads its own
     * bootstrap (CSRF + endpoint URLs) from a JSON script tag we inject
     * alongside the bundle reference.
     */
    private function maybeInjectCommentsOverlay(TemplateEvent $event): void
    {
        if ($event->templateMode !== View::TEMPLATE_MODE_CP) {
            return;
        }

        $request = Craft::$app->getRequest();
        if (! $request instanceof \craft\web\Request) {
            return;
        }
        if (! $request->getIsCpRequest() || $request->getIsAjax()) {
            return;
        }

        if (Craft::$app->getUser()->getIsGuest()) {
            return;
        }

        if ($event->output === '') {
            return;
        }

        $assetManager = Craft::$app->getAssetManager();
        $sourcePath = __DIR__.'/web/assets/comments/dist';

        try {
            $published = $assetManager->publish($sourcePath);
        } catch (\Throwable) {
            return;
        }

        $baseUrl = $published[1] ?? null;
        if (! is_string($baseUrl) || $baseUrl === '') {
            return;
        }

        $bootstrap = [
            'listUrl' => UrlHelper::cpUrl('ai/comments'),
            'resolveUrl' => UrlHelper::actionUrl('craft-ai/comments/resolve'),
            'openThreadUrl' => UrlHelper::actionUrl('craft-ai/comments/open-thread'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => $request->getCsrfToken(),
        ];

        $bootstrapJson = Json::htmlEncode($bootstrap);
        $jsUrl = $baseUrl.'/comments.js';
        $cssUrl = $baseUrl.'/comments.css';

        $snippet = <<<HTML
<link rel="stylesheet" href="{$cssUrl}">
<script type="application/json" data-craftai-comments-bootstrap>{$bootstrapJson}</script>
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
     * Append the per-field "AI fill" star overlay to every CP page response.
     *
     * Bundle behaviour mirrors the comments overlay — short-circuits when the
     * page lacks an `elementId` / `draftId` hidden input — so registering
     * globally is cheaper than trying to detect "is this an entry edit URL"
     * server-side and getting it wrong on third-party plugin routes.
     */
    private function maybeInjectAiStarOverlay(TemplateEvent $event): void
    {
        if ($event->templateMode !== View::TEMPLATE_MODE_CP) {
            return;
        }

        $request = Craft::$app->getRequest();
        if (! $request instanceof \craft\web\Request) {
            return;
        }
        if (! $request->getIsCpRequest() || $request->getIsAjax()) {
            return;
        }

        if (Craft::$app->getUser()->getIsGuest()) {
            return;
        }

        if ($event->output === '') {
            return;
        }

        $assetManager = Craft::$app->getAssetManager();
        $sourcePath = __DIR__.'/web/assets/aistar/dist';

        try {
            $published = $assetManager->publish($sourcePath);
        } catch (\Throwable) {
            return;
        }

        $baseUrl = $published[1] ?? null;
        if (! is_string($baseUrl) || $baseUrl === '') {
            return;
        }

        $bootstrap = [
            'fillFieldUrl' => UrlHelper::actionUrl('craft-ai/ai-star/fill-field'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => $request->getCsrfToken(),
        ];

        $bootstrapJson = Json::htmlEncode($bootstrap);
        $jsUrl = $baseUrl.'/aistar.js';
        $cssUrl = $baseUrl.'/aistar.css';

        $snippet = <<<HTML
<link rel="stylesheet" href="{$cssUrl}">
<script type="application/json" data-craftai-aistar-bootstrap>{$bootstrapJson}</script>
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
     */
    private function maybeInjectWidget(TemplateEvent $event): void
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
        $sourcePath = __DIR__.'/web/assets/widget/dist';

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
            'contextWindow' => $this->getSettingsArray()['contextWindow'],
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

    /**
     * @return array<array-key, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        return [...$item, 'url' => 'ai/sessions'];
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    /**
     * @return array{provider: ?string, apiKey: ?string, model: ?string, smallModel: ?string, system: ?string, baseUrl: ?string, contextWindow: ?int, imageProviders: array<string, array<string, mixed>>, mcpSessionCache: \Closure|string|null}
     */
    public function getSettingsArray(): array
    {
        /** @var array{provider?: ?string, apiKey?: ?string, model?: ?string, smallModel?: ?string, system?: ?string, baseUrl?: ?string, contextWindow?: int|null, imageProviders?: array<string, array<string, mixed>>, mcpSessionCache?: \Closure|string|null} $config */
        $config = Craft::$app->getConfig()->getConfigFromFile('craft-ai');

        $explicitContextWindow = $config['contextWindow'] ?? null;
        $contextWindow = is_int($explicitContextWindow) && $explicitContextWindow > 0
            ? $explicitContextWindow
            : self::defaultContextWindowFor(
                $config['provider'] ?? null,
                $config['model'] ?? null,
            );

        return [
            'provider' => $config['provider'] ?? null,
            'apiKey' => $config['apiKey'] ?? null,
            'model' => $config['model'] ?? null,
            'smallModel' => $config['smallModel'] ?? null,
            'system' => $config['system'] ?? null,
            'baseUrl' => $config['baseUrl'] ?? null,
            'contextWindow' => $contextWindow,
            'imageProviders' => is_array($config['imageProviders'] ?? null) ? $config['imageProviders'] : [],
            'mcpSessionCache' => $config['mcpSessionCache'] ?? null,
        ];
    }

    /**
     * Best-effort default context window per provider/model so the chat UI's
     * progress gauge and auto-compaction work out of the box for common
     * setups. Sites using an exotic model can override via
     * `contextWindow` in config/craft-ai.php.
     */
    private static function defaultContextWindowFor(?string $provider, ?string $model): ?int
    {
        $model = is_string($model) ? strtolower($model) : '';

        // DeepSeek-class models exposed via OpenAI-compatible endpoints
        // (opencode.ai zen, etc.) advertise a 1M-token window. Without this
        // hint the user wouldn't get a meaningful gauge — the most common
        // failure mode this feature is meant to address.
        if (str_contains($model, 'deepseek')) {
            return 1_048_576;
        }

        if (str_contains($model, 'claude-haiku')) {
            return 200_000;
        }
        if (str_contains($model, 'claude')) {
            return 200_000;
        }

        if (str_contains($model, 'gpt-4o-mini')) {
            return 128_000;
        }
        if (str_contains($model, 'gpt-4o')) {
            return 128_000;
        }
        if (str_contains($model, 'gpt-5') || str_contains($model, 'o3') || str_contains($model, 'o4')) {
            return 200_000;
        }

        // Conservative fallback by provider so the gauge still shows
        // something useful when the model name doesn't match a known prefix.
        return match ($provider) {
            'anthropic' => 200_000,
            'openai' => 128_000,
            default => null,
        };
    }

    public function getMcpSessionCache(): \yii\caching\CacheInterface
    {
        $override = $this->getSettingsArray()['mcpSessionCache'];

        if ($override instanceof \Closure) {
            $cache = $override();
        } elseif (is_string($override)) {
            $cache = Craft::$app->get($override);
        } else {
            $cache = Craft::$app->getCache();
        }

        if (! $cache instanceof \yii\caching\CacheInterface) {
            throw new \RuntimeException('craft-ai: mcpSessionCache must resolve to a yii\\caching\\CacheInterface instance.');
        }

        return $cache;
    }

    public function getSmallModelProvider(): LlmProvider
    {
        $settings = $this->getSettingsArray();
        $provider = $settings['provider'];
        $apiKey = $settings['apiKey'];

        if ($provider === null) {
            throw new \RuntimeException('craft-ai: no provider configured. Set "provider" in config/craft-ai.php to "anthropic" or "openai".');
        }
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException("craft-ai: provider \"{$provider}\" is configured but apiKey is missing in config/craft-ai.php.");
        }

        return match ($provider) {
            'anthropic' => new AnthropicProvider($apiKey, $settings['smallModel'] ?? $settings['model'] ?? 'claude-haiku-4-5-20251001'),
            'openai' => new OpenAiProvider($apiKey, $settings['smallModel'] ?? $settings['model'] ?? 'gpt-4o-mini', baseUrl: $settings['baseUrl'] ?? null),
            default => throw new \RuntimeException("craft-ai: unknown provider \"{$provider}\". Use \"anthropic\" or \"openai\"."),
        };
    }

    private function registerContainerBindings(): void
    {
        Craft::$container->setSingleton(ToolRegistry::class, fn () => $this->toolRegistry);
        Craft::$container->setSingleton(ToolContext::class);
        Craft::$container->setSingleton(PreviewService::class);
        Craft::$container->setSingleton(AutomationDispatcher::class);

        Craft::$container->setSingleton(LlmProvider::class, function (): LlmProvider {
            $settings = $this->getSettingsArray();
            $provider = $settings['provider'];
            $apiKey = $settings['apiKey'];

            if ($provider === null) {
                throw new \RuntimeException('craft-ai: no provider configured. Set "provider" in config/craft-ai.php to "anthropic" or "openai".');
            }
            if ($apiKey === null || $apiKey === '') {
                throw new \RuntimeException("craft-ai: provider \"{$provider}\" is configured but apiKey is missing in config/craft-ai.php.");
            }

            return match ($provider) {
                'anthropic' => new AnthropicProvider($apiKey, $settings['model'] ?? 'claude-sonnet-4-20250514'),
                'openai' => new OpenAiProvider($apiKey, $settings['model'] ?? 'gpt-4o', baseUrl: $settings['baseUrl'] ?? null),
                default => throw new \RuntimeException("craft-ai: unknown provider \"{$provider}\". Use \"anthropic\" or \"openai\"."),
            };
        });

        Craft::$container->setSingleton(AgentLoop::class, fn () => new AgentLoop(
            Craft::$container->get(LlmProvider::class),
            $this->toolRegistry,
            Craft::$container->get(ToolContext::class),
        ));

        $this->bindImageProviders();
        $this->bindSearchProviders();
    }

    /**
     * Fire {@see self::EVENT_REGISTER_AGENT_TOOLS} so other plugins (and
     * our own bundled {@see \markhuot\craftai\fields\CodeComponentModule})
     * can contribute tools without modifying this class. Each listed tool
     * is added to the shared registry exactly as if the plugin had called
     * `register()` directly. Duplicate registrations clobber by name;
     * listeners that need cpOnly semantics opt in per entry.
     */
    private function dispatchAgentToolRegistration(): void
    {
        $event = new RegisterAgentToolsEvent();
        $this->trigger(self::EVENT_REGISTER_AGENT_TOOLS, $event);

        foreach ($event->tools as $tool) {
            $this->toolRegistry->register($tool['class'], $tool['cpOnly'] ?? false);
        }
    }

    /**
     * Read `imageProviders` from config and register the matching tool for
     * each entry. Tools register only when their provider is configured —
     * so a site that only sets `openai` won't expose `generate_image_nano_banana`
     * to the agent at all (no opportunity for the model to call a tool that
     * would fail on missing credentials).
     */
    private function registerImageTools(): void
    {
        $settings = $this->getSettingsArray();
        $providers = $settings['imageProviders'];

        if (isset($providers['openai'])) {
            $this->toolRegistry->register(GenerateImageGptImage::class);
        }
        if (isset($providers['gemini'])) {
            $this->toolRegistry->register(GenerateImageNanoBanana::class);
        }
    }

    /**
     * Bind the per-provider image clients. Each binding is conditional on the
     * matching `imageProviders.<key>` entry being present and complete; a
     * missing or incomplete entry throws when the container resolves the
     * binding (which only happens if the corresponding tool is invoked,
     * since {@see registerImageTools} also gates tool registration on the
     * config presence).
     */
    private function bindImageProviders(): void
    {
        Craft::$container->setSingleton(OpenAiImageProvider::class, function (): OpenAiImageProvider {
            $config = $this->imageProviderConfig('openai');

            return new OpenAiImageProvider(
                apiKey: $config['apiKey'],
                baseUrl: $config['baseUrl'] ?? null,
            );
        });

        Craft::$container->setSingleton(GeminiImageProvider::class, function (): GeminiImageProvider {
            $config = $this->imageProviderConfig('gemini');

            return new GeminiImageProvider(
                apiKey: $config['apiKey'],
                model: is_string($config['model'] ?? null) ? $config['model'] : 'gemini-2.5-flash-image',
                baseUrl: $config['baseUrl'] ?? null,
            );
        });
    }

    /**
     * Pull a single image provider's config out of `imageProviders.<key>`,
     * raising a clear error when it's missing or incomplete. Tools won't be
     * registered for missing providers, so this is mainly a guard against a
     * partially-configured entry (the key exists but apiKey is empty).
     *
     * @return array{apiKey: string, model?: ?string, baseUrl?: ?string}
     */
    private function imageProviderConfig(string $key): array
    {
        $settings = $this->getSettingsArray();
        $providers = $settings['imageProviders'];
        $config = $providers[$key] ?? null;

        if (! is_array($config)) {
            throw new \RuntimeException(
                "craft-ai: image provider \"{$key}\" is not configured. Add it under "
                ."imageProviders in config/craft-ai.php.",
            );
        }

        $apiKey = $config['apiKey'] ?? null;
        if (! is_string($apiKey) || $apiKey === '') {
            throw new \RuntimeException(
                "craft-ai: image provider \"{$key}\" is missing an apiKey in "
                ."config/craft-ai.php.",
            );
        }

        $resolved = ['apiKey' => $apiKey];
        if (array_key_exists('model', $config) && (is_string($config['model']) || $config['model'] === null)) {
            $resolved['model'] = $config['model'];
        }
        if (array_key_exists('baseUrl', $config) && (is_string($config['baseUrl']) || $config['baseUrl'] === null)) {
            $resolved['baseUrl'] = $config['baseUrl'];
        }

        return $resolved;
    }

    /**
     * Register the `search_the_web` tool unless the user has explicitly
     * opted out. Both backing providers are keyless scrapers, so there's no
     * credential check that would otherwise gate registration — the tool
     * just works by default.
     *
     * Opt-out shapes recognized by {@see resolveSearchProvidersConfig}:
     *   'searchProviders' => null               // disable entirely
     *   'searchProviders' => ['default' => null] // same, more explicit
     */
    private function registerSearchTools(): void
    {
        if (self::resolveSearchProvidersConfig($this->rawConfig()) === null) {
            return;
        }

        $this->toolRegistry->register(SearchTheWeb::class);
    }

    /**
     * Build the {@see SearchProviderRegistry} singleton. Both keyless
     * providers register unconditionally; per-provider config (e.g.
     * `baseUrl` override) is optional. The `default` key picks which
     * provider answers a `search_the_web` call that omits `provider:`.
     */
    private function bindSearchProviders(): void
    {
        if (self::resolveSearchProvidersConfig($this->rawConfig()) === null) {
            return;
        }

        Craft::$container->setSingleton(SearchProviderRegistry::class, function (): SearchProviderRegistry {
            $resolved = self::resolveSearchProvidersConfig($this->rawConfig());
            // The registerSearchTools check guarantees this is non-null, but
            // re-asserting keeps PHPStan happy and the binding self-contained.
            if ($resolved === null) {
                throw new \RuntimeException('craft-ai: search providers are disabled.');
            }

            $braveConfig = is_array($resolved['brave'] ?? null) ? $resolved['brave'] : [];
            $ddgConfig = is_array($resolved['duckduckgo'] ?? null) ? $resolved['duckduckgo'] : [];

            /** @var list<SearchProvider> $instances */
            $instances = [
                $this->makeBraveSearchProvider($braveConfig),
                $this->makeDuckDuckGoSearchProvider($ddgConfig),
            ];

            return new SearchProviderRegistry($instances, $resolved['default']);
        });
    }

    /**
     * Read the raw `craft-ai` config file. Direct access (rather than going
     * through {@see getSettingsArray}) is what lets the search-provider
     * resolver distinguish `searchProviders => null` (explicit disable) from
     * the key being absent (use defaults) — the settings array would collapse
     * both into the same value.
     *
     * @return array<string, mixed>
     */
    private function rawConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = Craft::$app->getConfig()->getConfigFromFile('craft-ai');

        return $config;
    }

    /**
     * Resolve the `searchProviders` config block into a normalized shape.
     * Returns null to signal "tool disabled, don't register"; otherwise
     * returns the resolved settings with `default` filled in and any unknown
     * keys rejected.
     *
     * Recognized shapes:
     *   - key absent or non-array      -> use defaults (default = 'google')
     *   - `null`                       -> disabled
     *   - array with `default => null` -> disabled
     *   - array                        -> use as-is with `default => 'google'` default
     *
     * @param  array<string, mixed>  $rawConfig  Raw `craft-ai` config file contents.
     * @return array{default: string, brave?: array<string, mixed>, duckduckgo?: array<string, mixed>}|null
     */
    public static function resolveSearchProvidersConfig(array $rawConfig): ?array
    {
        $supported = ['brave', 'duckduckgo'];

        // Key absent → use defaults. (We check `array_key_exists` so the
        // explicit-null case below stays distinguishable.)
        if (! array_key_exists('searchProviders', $rawConfig)) {
            return ['default' => 'brave'];
        }

        $raw = $rawConfig['searchProviders'];

        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            // Garbage value — be forgiving and treat as defaults rather than
            // bricking the plugin boot. The example config documents the
            // valid shapes so this is mostly a typo guard.
            return ['default' => 'brave'];
        }

        // Explicit "default => null" is the verbose way to disable, mirroring
        // the top-level `null`. Useful when the user wants to leave the
        // `searchProviders` block in place but turn the tool off.
        if (array_key_exists('default', $raw) && $raw['default'] === null) {
            return null;
        }

        $default = $raw['default'] ?? null;
        if (! is_string($default) || $default === '') {
            $default = 'brave';
        }

        if (! in_array($default, $supported, true)) {
            throw new \RuntimeException(
                "craft-ai: unknown default search provider \"{$default}\" in "
                ."config/craft-ai.php. Supported: ".implode(', ', $supported).'.',
            );
        }

        // Reject typos in provider keys so a misnamed config block doesn't
        // silently lose its overrides.
        $allowedKeys = array_merge(['default'], $supported);
        foreach (array_keys($raw) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \RuntimeException(
                    "craft-ai: unknown search provider \"{$key}\" in "
                    ."config/craft-ai.php. Supported: ".implode(', ', $supported).'.',
                );
            }
        }

        /** @var array{default: string, brave?: array<string, mixed>, duckduckgo?: array<string, mixed>} $resolved */
        $resolved = ['default' => $default];
        foreach ($supported as $name) {
            if (isset($raw[$name]) && is_array($raw[$name])) {
                /** @var array<string, mixed> $providerConfig */
                $providerConfig = $raw[$name];
                $resolved[$name] = $providerConfig;
            }
        }

        return $resolved;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Render the CP settings form. Craft hands us a partial-page surface
     * (the outer `<form>` and save button are provided by the framework's
     * settings layout), so the template only needs to render the read-
     * only automations + commands lists. Actual editing happens on
     * dedicated screens under `ai/automations/*` and `ai/commands/*`.
     */
    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        if (! $settings instanceof Settings) {
            return null;
        }

        // The read-only rows only need labels + scope kinds to render —
        // the section/volume option lists live on the dedicated edit
        // controllers, which build them per-request.
        $eventChoices = Automation::eventChoices();
        $scopeByEvent = [];
        foreach (array_keys($eventChoices) as $event) {
            $scopeByEvent[$event] = Automation::scopeFor($event);
        }

        return Craft::$app->getView()->renderTemplate('craft-ai/settings', [
            'settings' => $settings,
            'eventChoices' => $eventChoices,
            'scopeByEvent' => $scopeByEvent,
        ]);
    }

    /**
     * Register Craft event listeners that hand off to the
     * {@see AutomationDispatcher}. We register one listener per Craft
     * event class regardless of how many automations exist, and let the
     * dispatcher filter at fire time — this keeps settings changes live
     * without re-booting the plugin, and avoids surprising "I disabled
     * the rule but it still fires" behavior from cached listener state.
     *
     * Listeners are deliberately registered after
     * {@see registerContainerBindings} so the dispatcher singleton is
     * always resolvable at fire time. We re-resolve per fire (rather
     * than capturing in a closure variable) so a container rebind from
     * tests sees the new instance.
     */
    private function registerAutomationListeners(): void
    {
        $dispatch = static function (string $eventKey, ?\craft\base\ElementInterface $element): void {
            if ($element === null) {
                return;
            }
            try {
                /** @var AutomationDispatcher $dispatcher */
                $dispatcher = Craft::$container->get(AutomationDispatcher::class);
            } catch (\Throwable) {
                return;
            }
            $dispatcher->dispatch($eventKey, $element);
        };

        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, static function (ModelEvent $event) use ($dispatch): void {
            $sender = $event->sender;
            if (! $sender instanceof Entry) {
                return;
            }
            // Propagating saves fire EVENT_AFTER_SAVE per site. Without
            // this guard a multi-site save would dispatch N automations
            // for the same logical edit.
            if ($sender->propagating) {
                return;
            }
            // Resave queue jobs (Craft's own bulk re-save) and similar
            // background fixups set $resaving = true. Treat those like
            // propagation — the editor didn't actually touch the entry.
            if ($sender->resaving) {
                return;
            }

            $eventKey = $sender->getIsDraft()
                ? Automation::EVENT_DRAFT_SAVED
                : Automation::EVENT_ENTRY_SAVED;
            $dispatch($eventKey, $sender);
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_DELETE, static function (Event $event) use ($dispatch): void {
            $sender = $event->sender;
            if (! $sender instanceof Entry) {
                return;
            }
            $dispatch(Automation::EVENT_ENTRY_DELETED, $sender);
        });

        Event::on(Drafts::class, Drafts::EVENT_AFTER_APPLY_DRAFT, static function (DraftEvent $event) use ($dispatch): void {
            $dispatch(Automation::EVENT_DRAFT_APPLIED, $event->canonical);
        });

        Event::on(Asset::class, Asset::EVENT_AFTER_SAVE, static function (ModelEvent $event) use ($dispatch): void {
            $sender = $event->sender;
            if (! $sender instanceof Asset) {
                return;
            }
            if ($sender->propagating) {
                return;
            }
            $dispatch(Automation::EVENT_ASSET_SAVED, $sender);
        });
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function makeBraveSearchProvider(array $config): BraveSearchProvider
    {
        $baseUrl = $config['baseUrl'] ?? null;

        return new BraveSearchProvider(
            baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function makeDuckDuckGoSearchProvider(array $config): DuckDuckGoSearchProvider
    {
        $baseUrl = $config['baseUrl'] ?? null;

        return new DuckDuckGoSearchProvider(
            baseUrl: is_string($baseUrl) ? $baseUrl : null,
        );
    }
}
