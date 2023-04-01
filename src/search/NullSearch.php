<?php

namespace markhuot\craftai\search;

use markhuot\craftai\Ai;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

class NullSearch implements SearchInterface
{
    public function __construct()
    {
    }

    /**
     * @param  array<mixed>  $document
     */
    public function index(string $id, array $document): self
    {
        return $this;
    }

    /**
     * @param  array<double>  $vectors
     * @return array<mixed>
     */
    public function knnSearch(array $vectors, int $limit = 3): array
    {
        return [collect([]), []];
    }
}
