<?php

namespace markhuot\craftai\listeners;

use craft\events\RegisterUrlRulesEvent;

class RegisterCpUrlRules
{
    public function handle(RegisterUrlRulesEvent $event): void
    {
        $routes = include __DIR__.'/../../src/config/routes.php';
        foreach ($routes as $route) {
            $event->rules[$route['route']] = $route['controller'];
        }
    }
}
