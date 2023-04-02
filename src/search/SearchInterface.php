<?php

namespace markhuot\craftai\search;

interface SearchInterface
{
    /**
     * @param  array<mixed>  $document
     */
    public function index(string $id, array $document): self;

    /**
     * @param  array<double>  $vectors
     * @return array<mixed>
     */
    public function knnSearch(array $vectors, int $limit = 3): array;
}
