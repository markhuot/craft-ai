<?php

namespace markhuot\openai\helpers;

use markhuot\craftai\listeners\ListenerInterface;
use yii\base\Event;

/**
 * @param  \Closure  ...$events
 */
function listen(...$events): void
{
    /** @var callable(): array{0: string, 1: string, 2: class-string} $event */
    foreach ($events as $event) {
        try {
            [$class, $event, $handlerClass] = $event();

            /** @var ListenerInterface $handler */
            $handler = \Craft::$container->get($handlerClass);

            if (method_exists($handler, 'init')) {
                $handler->init();
            }

            Event::on($class, $event, fn (...$args) => \Craft::$container->invoke($handler->handle(...), $args));
        } catch (\Throwable $e) {
            if (preg_match('/Class ".+" not found/', $e->getMessage())) {
                continue;
            }

            throw $e;
        }
    }
}
