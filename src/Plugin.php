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
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\permissions\ToolPermissions;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\agent\providers\AnthropicProvider;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\OpenAiProvider;
use markhuot\craftai\tools\DeleteAssets;
use markhuot\craftai\tools\DeleteDrafts;
use markhuot\craftai\tools\DeleteEntries;
use markhuot\craftai\tools\DeleteEntryTypes;
use markhuot\craftai\tools\DeleteFields;
use markhuot\craftai\tools\DeleteSections;
use markhuot\craftai\tools\FetchWebpage;
use markhuot\craftai\tools\GetAsset;
use markhuot\craftai\tools\GetDraft;
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
    public string $schemaVersion = '1.7.0';

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
        $this->toolRegistry->register(GetVolumes::class);
        $this->toolRegistry->register(UpsertAsset::class);
        $this->toolRegistry->register(DeleteAssets::class);
        $this->toolRegistry->register(DeleteEntries::class);
        $this->toolRegistry->register(DeleteDrafts::class);
        $this->toolRegistry->register(DeleteSections::class);
        $this->toolRegistry->register(DeleteEntryTypes::class);
        $this->toolRegistry->register(DeleteFields::class);
        $this->toolRegistry->register(FetchWebpage::class, cpOnly: true);
        $this->toolRegistry->register(OpenPreview::class, cpOnly: true);
        $this->toolRegistry->register(GetPreview::class, cpOnly: true);

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
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => $request->getCsrfToken(),
            'context' => $context,
            'contextFingerprint' => PageContextSerializer::fingerprint($context),
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
     * @return array{provider: ?string, apiKey: ?string, model: ?string, smallModel: ?string, system: ?string, baseUrl: ?string, mcpSessionCache: \Closure|string|null}
     */
    public function getSettingsArray(): array
    {
        /** @var array{provider?: ?string, apiKey?: ?string, model?: ?string, smallModel?: ?string, system?: ?string, baseUrl?: ?string, mcpSessionCache?: \Closure|string|null} $config */
        $config = Craft::$app->getConfig()->getConfigFromFile('craft-ai');

        return [
            'provider' => $config['provider'] ?? null,
            'apiKey' => $config['apiKey'] ?? null,
            'model' => $config['model'] ?? null,
            'smallModel' => $config['smallModel'] ?? null,
            'system' => $config['system'] ?? null,
            'baseUrl' => $config['baseUrl'] ?? null,
            'mcpSessionCache' => $config['mcpSessionCache'] ?? null,
        ];
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
    }
}
