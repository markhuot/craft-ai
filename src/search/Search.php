<?php

namespace markhuot\craftai\search;

use markhuot\craftai\Ai;

/**
 * @mixin Opensearch
 */
class Search
{
    function __call(string $method, array $arguments)
    {
        return $this->getBackend()->{$method}(...$arguments);
    }

    function getBackend(): OpenSearch
    {
        $settings = Ai::getInstance()->getSettings();
        $driver = $settings->driver;

        $class = $settings->get('drivers.'.$driver.'.class');

        /** @var OpenSearch */
        return \Craft::$container->get($class);
    }
}
