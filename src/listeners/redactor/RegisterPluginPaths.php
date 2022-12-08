<?php

namespace markhuot\craftai\listeners\redactor;

use craft\redactor\events\RegisterPluginPathsEvent;
use craft\redactor\Field;

class RegisterPluginPaths
{
    function handle(RegisterPluginPathsEvent $event)
    {
        $event->paths[] = __DIR__ . '/../../redactor/';

        Field::registerRedactorPlugin('craftai-complete');
        Field::registerRedactorPlugin('craftai-edit');
    }
}
