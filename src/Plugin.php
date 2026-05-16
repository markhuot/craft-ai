<?php

namespace markhuot\craftai;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
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
use markhuot\craftai\permissions\ToolPermissions;
use markhuot\craftai\preview\PreviewService;
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
use markhuot\craftai\tools\GetDraft;
use markhuot\craftai\tools\GetImage;
use markhuot\craftai\tools\GetPreview;
use markhuot\craftai\tools\GetDrafts;
use markhuot\craftai\tools\GetEntries;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\GetEntryTypes;
use markhuot\craftai\tools\GetFields;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\GetSections;
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
use markhuot\craftai\tools\UpsertTemplate;
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

    public string $schemaVersion = '1.9.0';

    public bool $hasCpSection = true;

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
                    $this->lastRenderedTemplate = is_string($event->template) ? $event->template : null;
                }
            },
        );

        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
            function (TemplateEvent $event): void {
                $this->maybeInjectWidget($event);
            },
        );
    }

    /**
     * Append the front-end chat widget to a rendered site template when the
     * current visitor is a CP-capable user. We hook the post-render event
     * (rather than {% hook %} or EVENT_END_BODY) so the widget appears on
     * every site response without requiring template authors to opt in.
     */
    private function maybeInjectWidget(TemplateEvent $event): void
    {
        if ($event->templateMode !== View::TEMPLATE_MODE_SITE) {
            return;
        }

        $request = Craft::$app->getRequest();
        if (! $request instanceof \craft\web\Request) {
            return;
        }
        if ($request->getIsCpRequest() || $request->getIsAjax()) {
            return;
        }

        $user = Craft::$app->getUser();
        if ($user->getIsGuest()) {
            return;
        }
        if (! $user->checkPermission('accessCp')) {
            return;
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
     * @return array{url: ?string, path: ?string, query: array<string, mixed>, siteHandle: ?string, template: ?string, element: ?array{type: string, id: int, title: ?string, sectionHandle: ?string}}
     */
    private function gatherPageContext(\craft\web\Request $request): array
    {
        $url = $this->safeAbsoluteUrl($request);
        $path = $request->getPathInfo();

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

        $element = null;
        try {
            $matched = Craft::$app->getUrlManager()->getMatchedElement();
            if ($matched !== null) {
                $element = $this->summarizeElement($matched);
            }
        } catch (\Throwable) {
            // Some plugins or routes resolve outside the URL manager — ignore.
        }

        return [
            'url' => $url,
            'path' => $path !== '' ? $path : null,
            'query' => $query,
            'siteHandle' => $siteHandle,
            'template' => $this->lastRenderedTemplate,
            'element' => $element,
        ];
    }

    private function safeAbsoluteUrl(\craft\web\Request $request): ?string
    {
        try {
            $url = $request->getAbsoluteUrl();
            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Drop anything that can't round-trip cleanly through JSON (resources,
     * objects, etc.) so the bootstrap stays a flat scalar map.
     *
     * @param array<string, mixed> $params
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
     * @return array{type: string, id: int, title: ?string, sectionHandle: ?string}
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

        $title = method_exists($element, 'getUiLabel') ? (string) $element->getUiLabel() : null;
        if ($title === '' || $title === null) {
            $title = $element->title ?? null;
        }

        return [
            'type' => $type,
            'id' => (int) $element->id,
            'title' => is_string($title) && $title !== '' ? $title : null,
            'sectionHandle' => $sectionHandle,
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
        if (isset($config['model']) && (is_string($config['model']) || $config['model'] === null)) {
            $resolved['model'] = $config['model'];
        }
        if (isset($config['baseUrl']) && (is_string($config['baseUrl']) || $config['baseUrl'] === null)) {
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
