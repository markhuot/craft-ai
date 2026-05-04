<?php

namespace markhuot\craftai;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\agent\providers\AnthropicProvider;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\OpenAiProvider;
use markhuot\craftai\tools\DeleteDrafts;
use markhuot\craftai\tools\DeleteEntries;
use markhuot\craftai\tools\DeleteEntryTypes;
use markhuot\craftai\tools\DeleteFields;
use markhuot\craftai\tools\DeleteSections;
use markhuot\craftai\tools\GetDrafts;
use markhuot\craftai\tools\GetEntries;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\GetEntryTypes;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\GetSections;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;
use markhuot\craftai\tools\UpdateFieldLayout;
use markhuot\craftai\tools\UpsertEntryType;
use markhuot\craftai\tools\UpsertField;
use markhuot\craftai\tools\UpsertSection;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.4.0';

    public bool $hasCpSection = true;

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
        $this->toolRegistry->register(GetHealth::class);
        $this->toolRegistry->register(GetEntries::class);
        $this->toolRegistry->register(GetEntry::class);
        $this->toolRegistry->register(UpsertEntry::class);
        $this->toolRegistry->register(GetDrafts::class);
        $this->toolRegistry->register(UpsertDraft::class);
        $this->toolRegistry->register(GetSections::class);
        $this->toolRegistry->register(UpsertSection::class);
        $this->toolRegistry->register(GetEntryTypes::class);
        $this->toolRegistry->register(UpsertEntryType::class);
        $this->toolRegistry->register(UpsertField::class);
        $this->toolRegistry->register(UpdateFieldLayout::class);
        $this->toolRegistry->register(DeleteEntries::class);
        $this->toolRegistry->register(DeleteDrafts::class);
        $this->toolRegistry->register(DeleteSections::class);
        $this->toolRegistry->register(DeleteEntryTypes::class);
        $this->toolRegistry->register(DeleteFields::class);

        $this->registerContainerBindings();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'markhuot\\craftai\\console\\controllers';
        }

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['ai/sessions'] = 'craft-ai/sessions/index';
                $event->rules['ai/sessions/data'] = 'craft-ai/sessions/data';
                $event->rules['POST ai/sessions/new'] = 'craft-ai/sessions/new';
                $event->rules['POST ai/sessions/delete'] = 'craft-ai/sessions/delete';
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
     * @return array{provider: ?string, apiKey: ?string, model: ?string, smallModel: ?string, system: ?string, baseUrl: ?string, mcpUserId: int, mcpSessionCache: \Closure|string|null}
     */
    public function getSettingsArray(): array
    {
        /** @var array{provider?: ?string, apiKey?: ?string, model?: ?string, smallModel?: ?string, system?: ?string, baseUrl?: ?string, mcpUserId?: int, mcpSessionCache?: \Closure|string|null} $config */
        $config = Craft::$app->getConfig()->getConfigFromFile('craft-ai');

        return [
            'provider' => $config['provider'] ?? null,
            'apiKey' => $config['apiKey'] ?? null,
            'model' => $config['model'] ?? null,
            'smallModel' => $config['smallModel'] ?? null,
            'system' => $config['system'] ?? null,
            'baseUrl' => $config['baseUrl'] ?? null,
            'mcpUserId' => (int) ($config['mcpUserId'] ?? 1),
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
        ));
    }
}
