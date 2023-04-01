<?php

namespace markhuot\craftai\listeners\redactor;

use craft\redactor\events\ModifyRedactorConfigEvent;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\EditText;
use markhuot\craftai\models\Backend;

class DefineRedactorConfig
{
    public function handle(ModifyRedactorConfigEvent $event)
    {
        if (empty($event->config)) {
            if (Backend::for(Completion::class, true)) {
                $event->config['plugins'][] = 'craftai-complete';
            }
            if (Backend::for(EditText::class, true)) {
                $event->config['plugins'][] = 'craftai-edit';
            }
        }
    }
}
