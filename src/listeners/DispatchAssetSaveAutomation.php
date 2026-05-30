<?php

namespace markhuot\craftai\listeners;

use craft\elements\Asset;
use craft\events\ModelEvent;
use markhuot\craftai\models\Automation;

/**
 * Dispatch asset "saved" automations on Asset::EVENT_AFTER_SAVE.
 */
class DispatchAssetSaveAutomation
{
    use DispatchesAutomation;

    public function __invoke(ModelEvent $event): void
    {
        $sender = $event->sender;
        if (! $sender instanceof Asset) {
            return;
        }
        if ($sender->propagating) {
            return;
        }
        $this->dispatch(Automation::EVENT_ASSET_SAVED, $sender);
    }
}
