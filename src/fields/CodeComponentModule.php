<?php

namespace markhuot\craftai\fields;

use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use markhuot\craftai\agent\RegisterAgentToolsEvent;
use markhuot\craftai\Plugin;
use yii\base\Event;

/**
 * Self-contained module that wires the {@see CodeComponent} field type and
 * its companion {@see UpdateCodeComponent} agent tool into Craft. The
 * module subscribes to plugin events the same way an external plugin
 * would — it has no direct reference into `Plugin::init()` aside from the
 * one-line `bootstrap()` call — so it doubles as the canonical example
 * for how to register new agent tools from outside the base plugin.
 */
class CodeComponentModule
{
    /**
     * Idempotent: safe to call from `Plugin::init()` (or from tests) any
     * number of times. Event handlers are registered exactly once per
     * process by tracking a flag here.
     */
    private static bool $bootstrapped = false;

    public static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        self::$bootstrapped = true;

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = CodeComponent::class;
            },
        );

        Event::on(
            Plugin::class,
            Plugin::EVENT_REGISTER_AGENT_TOOLS,
            static function (RegisterAgentToolsEvent $event): void {
                $event->tools[] = [
                    'class' => UpdateCodeComponent::class,
                    'cpOnly' => true,
                ];
            },
        );
    }

    /**
     * Test seam — drop the bootstrapped flag so a fresh test run can
     * re-register the listeners against a freshly constructed plugin.
     * Never call from production code.
     */
    public static function resetForTests(): void
    {
        self::$bootstrapped = false;
    }
}
