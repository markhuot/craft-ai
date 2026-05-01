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
use markhuot\craftai\tools\CreateEntry;
use markhuot\craftai\tools\GetEntries;
use markhuot\craftai\tools\GetEntry;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\ToolRegistry;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

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
        $this->toolRegistry->register(CreateEntry::class);

        $this->registerContainerBindings();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'markhuot\\craftai\\console\\controllers';
        }

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['ai/sessions'] = 'craft-ai/sessions/index';
                $event->rules['POST ai/sessions/new'] = 'craft-ai/sessions/new';
                $event->rules['ai/session/<uuid:[A-Za-z0-9\-]+>'] = 'craft-ai/sessions/view';
            },
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['POST craft-ai/mcp'] = 'craft-ai/mcp/handle';
                $event->rules['GET craft-ai/mcp'] = 'craft-ai/mcp/handle';
                $event->rules['DELETE craft-ai/mcp'] = 'craft-ai/mcp/handle';
                $event->rules['OPTIONS craft-ai/mcp'] = 'craft-ai/mcp/handle';
            },
        );
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    /**
     * @return array{provider: ?string, apiKey: ?string, model: ?string, system: ?string, mcpUserId: int}
     */
    public function getSettingsArray(): array
    {
        /** @var array{provider?: ?string, apiKey?: ?string, model?: ?string, system?: ?string, mcpUserId?: int} $config */
        $config = Craft::$app->getConfig()->getConfigFromFile('craft-ai');

        return [
            'provider' => $config['provider'] ?? null,
            'apiKey' => $config['apiKey'] ?? null,
            'model' => $config['model'] ?? null,
            'system' => $config['system'] ?? null,
            'mcpUserId' => (int) ($config['mcpUserId'] ?? 1),
        ];
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
                'openai' => new OpenAiProvider($apiKey, $settings['model'] ?? 'gpt-4o'),
                default => throw new \RuntimeException("craft-ai: unknown provider \"{$provider}\". Use \"anthropic\" or \"openai\"."),
            };
        });

        Craft::$container->setSingleton(AgentLoop::class, fn () => new AgentLoop(
            Craft::$container->get(LlmProvider::class),
            $this->toolRegistry,
        ));
    }
}
