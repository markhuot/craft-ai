<?php

namespace markhuot\openai\helpers;

use yii\base\Event;

function listen(...$events)
{
    foreach ($events as $event) {
        try {
            [$class, $event, $handlerClass] = $event();

            $handler = \Craft::$container->get($handlerClass);

            if (method_exists($handler, 'init')) {
                $handler->init();
            }

            Event::on($class, $event, fn (...$args) => $handler->handle(...$args));
        }
        catch (\Throwable $e) {
            if (preg_match('/Class ".+" not found/', $e->getMessage())) {
                continue;
            }

            throw $e;
        }
    }
}
