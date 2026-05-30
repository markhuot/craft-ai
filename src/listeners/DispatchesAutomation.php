<?php

namespace markhuot\craftai\listeners;

use Craft;
use craft\base\ElementInterface;
use markhuot\craftai\services\AutomationDispatcher;

/**
 * Shared dispatch helper for the automation listeners. We register one
 * listener per Craft event class regardless of how many automations exist,
 * and let the {@see AutomationDispatcher} filter at fire time — this keeps
 * settings changes live without re-booting the plugin, and avoids
 * surprising "I disabled the rule but it still fires" behavior from cached
 * listener state.
 *
 * The dispatcher is re-resolved from the container per fire (rather than
 * captured at registration) so a container rebind from tests sees the new
 * instance.
 */
trait DispatchesAutomation
{
    protected function dispatch(string $eventKey, ?ElementInterface $element): void
    {
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
    }
}
