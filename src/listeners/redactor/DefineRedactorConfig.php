<?php

namespace markhuot\craftai\listeners\redactor;

use craft\redactor\events\ModifyRedactorConfigEvent;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\EditText;
use markhuot\craftai\models\Backend;

class DefineRedactorConfig
{
    public function handle(ModifyRedactorConfigEvent $event): void
    {
        if (empty($event->config)) {
            if (Backend::can(Completion::class)) {
                $event->config['plugins'][] = 'craftai-complete';
            }
            if (Backend::can(EditText::class)) {
                $event->config['plugins'][] = 'craftai-edit';
            }
        }
    }
}
