<?php

namespace markhuot\craftai\listeners\redactor;

use craft\redactor\events\ModifyRedactorConfigEvent;

class DefineRedactorConfig
{
    function handle(ModifyRedactorConfigEvent $event)
    {
        if (empty($event->config)) {
            $event->config['plugins'][] = 'craftai-complete';
            $event->config['plugins'][] = 'craftai-edit';
        }
    }
}
