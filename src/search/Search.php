<?php

namespace markhuot\craftai\search;

use markhuot\craftai\Ai;

/**
 * Search vcector backends
 *
 * https://hub.docker.com/r/opensearchproject/opensearch#!
 * https://weaviate.io/developers/weaviate/installation
 *
 * @mixin Opensearch
 */
class Search
{
    public function __call(string $method, array $arguments)
    {
        return $this->getBackend()->{$method}(...$arguments);
    }

    public function getBackend(?string $driver = null): OpenSearch
    {
        $settings = Ai::getInstance()->getSettings();
        $driver = $driver ?? $settings->driver;

        $class = $settings->get('drivers.'.$driver.'.class');

        /** @var OpenSearch */
        return \Craft::$container->get($class);
    }
}
