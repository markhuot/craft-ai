<?php

namespace markhuot\craftai;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\controllers\ElementsController;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\services\Drafts;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\RegisterAgentToolsEvent;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\fields\CodeComponentModule;
use markhuot\craftai\listeners\AllowCkeditorCommentMarkup;
use markhuot\craftai\listeners\CkeditorFieldNotes;
use markhuot\craftai\listeners\DefineCompareButton;
use markhuot\craftai\listeners\DispatchAssetSaveAutomation;
use markhuot\craftai\listeners\DispatchDraftAppliedAutomation;
use markhuot\craftai\listeners\DispatchEntryDeleteAutomation;
use markhuot\craftai\listeners\DispatchEntrySaveAutomation;
use markhuot\craftai\listeners\InjectAiStarOverlay;
use markhuot\craftai\listeners\InjectChatWidget;
use markhuot\craftai\listeners\InjectCommentsOverlay;
use markhuot\craftai\listeners\InjectElementContext;
use markhuot\craftai\listeners\RegisterCkeditorCommentImport;
use markhuot\craftai\listeners\RegisterCpUrlRules;
use markhuot\craftai\listeners\RegisterCraftAiPermissions;
use markhuot\craftai\listeners\RegisterSiteUrlRules;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Settings;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\services\AutomationDispatcher;
use markhuot\craftai\agent\providers\BraveSearchProvider;
use markhuot\craftai\agent\providers\DuckDuckGoSearchProvider;
use markhuot\craftai\agent\providers\GeminiImageProvider;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\MakeLlmProvider;
use markhuot\craftai\agent\providers\OpenAiImageProvider;
use markhuot\craftai\agent\providers\ReportsContextWindow;
use markhuot\craftai\agent\providers\SearchProvider;
use markhuot\craftai\agent\providers\SearchProviderRegistry;
use markhuot\craftai\tools\ApplyDraft;
use markhuot\craftai\tools\DeleteAssets;
use markhuot\craftai\tools\DeleteDrafts;
use markhuot\craftai\tools\DeleteEntries;
use markhuot\craftai\tools\DeleteEntryTypes;
use markhuot\craftai\tools\DeleteFields;
use markhuot\craftai\tools\DeleteSections;
use markhuot\craftai\tools\DiffRevisions;
use markhuot\craftai\tools\FetchWebpage;
use markhuot\craftai\tools\GenerateImageGptImage;
use markhuot\craftai\tools\GenerateImageNanoBanana;
use markhuot\craftai\tools\GetAsset;
use markhuot\craftai\tools\GetAssets;
use markhuot\craftai\tools\GetComments;
use markhuot\craftai\tools\GetDraft;
use markhuot\craftai\tools\LeaveComment;
use markhuot\craftai\tools\OpenArtifact;
use markhuot\craftai\tools\RenderArtifact;
use markhuot\craftai\tools\ResolveComment;
use markhuot\craftai\tools\GetImage;
use markhuot\craftai\tools\GetPreview;
use markhuot\craftai\tools\GetRevision;
use markhuot\craftai\tools\GetRevisions;
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

    public string $schemaVersion = '1.12.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    private ToolRegistry $toolRegistry;

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
        $this->registerBasicTools();
        $this->registerImageTools();
        $this->registerSearchTools();

        // PoC consumer of the public registration event — also wires the
        // field type into Craft. Doing this *before* firing the event keeps
        // the bundled module on equal footing with any external listener.
        CodeComponentModule::bootstrap();

        $this->dispatchAgentToolRegistration();

        $this->registerContainerBindings();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'markhuot\\craftai\\console\\controllers';
        }

        // Automation dispatchers — one listener per Craft event class. The
        // AutomationDispatcher filters at fire time so settings changes stay
        // live without re-booting the plugin (no "I disabled the rule but it
        // still fires" surprises). Registered after registerContainerBindings()
        // above so the dispatcher singleton resolves, and the dispatchers
        // re-resolve it per fire so a test container rebind is honored.
        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, new DispatchEntrySaveAutomation());
        Event::on(Entry::class, Entry::EVENT_AFTER_DELETE, new DispatchEntryDeleteAutomation());
        Event::on(Drafts::class, Drafts::EVENT_AFTER_APPLY_DRAFT, new DispatchDraftAppliedAutomation());
        Event::on(Asset::class, Asset::EVENT_AFTER_SAVE, new DispatchAssetSaveAutomation());

        Event::on(
            UpsertField::class,
            UpsertField::EVENT_DEFINE_FIELD_NOTES,
            new CkeditorFieldNotes(),
        );

        $this->registerCkeditorCommentPlugin();

        // Adds a "Compare…" button to the entry-edit action buttons, next to
        // Preview, opening the revision compare screen. See {@see DefineCompareButton}.
        Event::on(
            Entry::class,
            Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
            new DefineCompareButton(),
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            new RegisterCraftAiPermissions($this->toolRegistry),
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            new RegisterCpUrlRules(),
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            new RegisterSiteUrlRules(),
        );

        // The chat widget injector owns the captured-template state shared
        // between the before- and after-render events, so it's registered as
        // a single instance against both. The comments + AI-star overlays are
        // stateless and self-contained, one listener each.
        $chatWidget = new InjectChatWidget();
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            [$chatWidget, 'captureTemplate'],
        );
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            $chatWidget,
        );
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            new InjectCommentsOverlay(),
        );
        // Hand the front-end the authoritative (canonical/draft) identity
        // of the element being edited, so the comments overlay never has
        // to infer it from Craft's (canonical-only) form inputs.
        Event::on(
            ElementsController::class,
            ElementsController::EVENT_DEFINE_EDITOR_CONTENT,
            new InjectElementContext(),
        );
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            new InjectAiStarOverlay(),
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
            new RegisterCkeditorCommentImport(),
        );

        Event::on(
            \craft\ckeditor\Field::class,
            \craft\ckeditor\Field::EVENT_MODIFY_PURIFIER_CONFIG,
            new AllowCkeditorCommentMarkup(),
        );
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

        return [
            'provider' => $config['provider'] ?? null,
            'apiKey' => $config['apiKey'] ?? null,
            'model' => $config['model'] ?? null,
            'smallModel' => $config['smallModel'] ?? null,
            'system' => $config['system'] ?? null,
            'baseUrl' => $config['baseUrl'] ?? null,
            // The *configured* window only — the resolved value (config →
            // API discovery → fallback) lives in {@see getContextWindow}, which
            // is what callers should read. Kept here null unless explicitly set
            // so this hot-path array never triggers the discovery network call.
            'contextWindow' => is_int($explicitContextWindow) && $explicitContextWindow > 0 ? $explicitContextWindow : null,
            'imageProviders' => is_array($config['imageProviders'] ?? null) ? $config['imageProviders'] : [],
            'mcpSessionCache' => $config['mcpSessionCache'] ?? null,
        ];
    }

    /**
     * Resolve the model's context window for the chat gauge and auto-compaction.
     * The first-party OpenAI/Anthropic message APIs never report it (the
     * response `usage` block is tokens *consumed*, not the ceiling), so the
     * resolution order is: explicit `contextWindow` config → live discovery
     * from the provider's `/models` endpoint (cached; only OpenAI-compatible
     * gateways like OpenRouter / opencode zen report it) → a conservative
     * per-provider fallback so the common first-party setup still gets a gauge
     * out of the box → null (gauge hidden).
     */
    public function getContextWindow(): ?int
    {
        $settings = $this->getSettingsArray();

        // 1. Explicit config always wins — the only value we never second-guess.
        if (is_int($settings['contextWindow']) && $settings['contextWindow'] > 0) {
            return $settings['contextWindow'];
        }

        // 2. Ask the provider's API (cached). Returns null for the first-party
        //    APIs, which don't expose the window anywhere.
        $discovered = $this->discoverContextWindow();
        if ($discovered !== null && $discovered > 0) {
            return $discovered;
        }

        // 3. Conservative fallback for the first-party providers that can't tell
        //    us, so a default install still shows a (roughly right) gauge. These
        //    are the only two numbers we still hard-code — both are stable,
        //    long-standing first-party defaults rather than per-model guesses.
        return match ($settings['provider']) {
            'anthropic' => 200_000,
            'openai' => 128_000,
            default => null,
        };
    }

    /**
     * Query the configured provider for its context window, caching the result
     * (hits and misses) for a day so this never repeats the `/models` round
     * trip on every gauge render. Resolves the provider from the container so a
     * rebound test double is honored; providers that can't report a window
     * (everything but {@see ReportsContextWindow}) short-circuit to null.
     */
    private function discoverContextWindow(): ?int
    {
        $settings = $this->getSettingsArray();
        if ($settings['provider'] === null || $settings['apiKey'] === null || $settings['apiKey'] === '') {
            return null;
        }

        $cache = Craft::$app->getCache();
        $cacheKey = 'craft-ai:contextWindow:'.md5(implode('|', [
            (string) $settings['provider'],
            (string) ($settings['model'] ?? ''),
            (string) ($settings['baseUrl'] ?? ''),
        ]));

        if ($cache !== null) {
            $cached = $cache->get($cacheKey);
            if ($cached !== false) {
                // 'miss' is the sentinel for "asked, nothing reported" so a
                // null-returning provider isn't re-queried every request.
                return is_int($cached) ? $cached : null;
            }
        }

        try {
            $provider = Craft::$container->get(LlmProvider::class);
            $window = $provider instanceof ReportsContextWindow ? $provider->contextWindow() : null;
        } catch (\Throwable) {
            $window = null;
        }

        $cache?->set($cacheKey, $window ?? 'miss', 86400);

        return $window;
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

        return (new MakeLlmProvider())(
            $settings['provider'],
            $settings['apiKey'],
            $settings['smallModel'] ?? $settings['model'],
            $settings['baseUrl'] ?? null,
            small: true,
        );
    }

    private function registerContainerBindings(): void
    {
        Craft::$container->setSingleton(ToolRegistry::class, fn () => $this->toolRegistry);
        Craft::$container->setSingleton(ToolContext::class);
        Craft::$container->setSingleton(PreviewService::class);
        Craft::$container->setSingleton(AutomationDispatcher::class);

        Craft::$container->setSingleton(LlmProvider::class, function (): LlmProvider {
            $settings = $this->getSettingsArray();

            return (new MakeLlmProvider())(
                $settings['provider'],
                $settings['apiKey'],
                $settings['model'],
                $settings['baseUrl'] ?? null,
            );
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
     * Register the always-on built-in agent tools. Provider-gated tools
     * (image generation, web search) register from their own dedicated
     * methods so a missing-credentials provider never exposes a tool the
     * model would only fail to call.
     */
    private function registerBasicTools(): void
    {
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
        // Revision compare tools. All read-only, so they're available over MCP
        // and in read-only sessions; render_artifact / open_artifact (below)
        // are the CP-only pieces that persist and surface a rendered diff in
        // the chat preview pane.
        $this->toolRegistry->register(GetRevisions::class);
        $this->toolRegistry->register(GetRevision::class);
        $this->toolRegistry->register(DiffRevisions::class);
        // CP-only artifact pair. render_artifact persists agent-authored HTML
        // (e.g. a rendered diff) to the database; open_artifact mounts a saved
        // artifact in the chat preview pane. Untrusted HTML, served sandboxed.
        $this->toolRegistry->register(RenderArtifact::class, cpOnly: true);
        $this->toolRegistry->register(OpenArtifact::class, cpOnly: true);
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
