<?php

namespace markhuot\craftai\listeners;

use craft\events\RegisterComponentTypesEvent;
use markhuot\craftai\fields\Ai;

class AddAiField
{
    public function handle(RegisterComponentTypesEvent $event)
    {
        $event->types[] = Ai::class;
    }
}
