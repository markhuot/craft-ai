<?php

namespace markhuot\craftai\listeners;

use craft\events\RegisterComponentTypesEvent;
use markhuot\craftai\fields\Ai;
use yii\base\Event;

class AddAiField implements ListenerInterface
{
    /**
     * @param  RegisterComponentTypesEvent  $event
     */
    public function handle(Event $event): void
    {
        $event->types[] = Ai::class;
    }
}
