<?php

namespace markhuot\craftai\listeners;

use craft\events\DraftEvent;
use markhuot\craftai\models\Automation;

/**
 * Dispatch "draft applied" automations on Drafts::EVENT_AFTER_APPLY_DRAFT.
 */
class DispatchDraftAppliedAutomation
{
    use DispatchesAutomation;

    public function __invoke(DraftEvent $event): void
    {
        $this->dispatch(Automation::EVENT_DRAFT_APPLIED, $event->canonical);
    }
}
