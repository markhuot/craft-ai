<?php

namespace markhuot\craftai\listeners;

use craft\events\RegisterUrlRulesEvent;

class RegisterCpUrlRules
{
    function handle(RegisterUrlRulesEvent $event)
    {
        $routes = include __DIR__.'/../../src/config/routes.php';
        foreach ($routes as $route) {
            $event->rules[$route['route']] = $route['controller'];
        }
    }
}
