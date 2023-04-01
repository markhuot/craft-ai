<?php

namespace markhuot\craftai\search;

use markhuot\craftai\Ai;

/**
 * Search vcector backends
 *
 * https://hub.docker.com/r/opensearchproject/opensearch#!
 * https://weaviate.io/developers/weaviate/installation
 *
 * @mixin SearchInterface
 */
class Search
{
    /**
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->getBackend()->{$method}(...$arguments);
    }

    public function getBackend(?string $driver = null): OpenSearch|NullSearch
    {
        $settings = Ai::getInstance()->getSettings();
        $driver = $driver ?? $settings->searchDriver;

        $class = $settings->get('searchDrivers.'.$driver.'.class');

        /** @var OpenSearch */
        return \Craft::$container->get($class);
    }
}
