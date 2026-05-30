<?php

namespace markhuot\craftai\listeners;

use craft\elements\Entry;
use craft\events\ModelEvent;
use markhuot\craftai\models\Automation;

/**
 * Dispatch entry/draft "saved" automations on Entry::EVENT_AFTER_SAVE.
 */
class DispatchEntrySaveAutomation
{
    use DispatchesAutomation;

    public function __invoke(ModelEvent $event): void
    {
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
        $this->dispatch($eventKey, $sender);
    }
}
