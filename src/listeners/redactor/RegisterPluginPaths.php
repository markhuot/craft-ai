<?php

namespace markhuot\craftai\listeners\redactor;

use craft\redactor\events\RegisterPluginPathsEvent;

class RegisterPluginPaths
{
    public function handle(RegisterPluginPathsEvent $event): void
    {
        $event->paths[] = __DIR__.'/../../redactor/';
    }
}
