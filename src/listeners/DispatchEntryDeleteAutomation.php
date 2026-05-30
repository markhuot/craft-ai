<?php

namespace markhuot\craftai\listeners;

use craft\elements\Entry;
use markhuot\craftai\models\Automation;
use yii\base\Event;

/**
 * Dispatch entry "deleted" automations on Entry::EVENT_AFTER_DELETE.
 */
class DispatchEntryDeleteAutomation
{
    use DispatchesAutomation;

    public function __invoke(Event $event): void
    {
        $sender = $event->sender;
        if (! $sender instanceof Entry) {
            return;
        }
        $this->dispatch(Automation::EVENT_ENTRY_DELETED, $sender);
    }
}
