<?php

namespace markhuot\craftai\search;

use Illuminate\Support\Collection;

interface SearchInterface
{
    /**
     * @param  array<mixed>  $document
     */
    public function index(string $id, array $document): self;

    /**
     * @param  array<double>  $vectors
     * @return array{Collection<array-key, array<array-key, mixed>>, array<array-key, mixed>}
     */
    public function knnSearch(array $vectors, int $limit = 3): array;
}
