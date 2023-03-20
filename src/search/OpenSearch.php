<?php

namespace markhuot\craftai\search;

use markhuot\craftai\Ai;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

class OpenSearch
{
    protected Client $client;

    public function __construct()
    {
        $this->connect();
    }

    protected function connect(): self
    {
        $settings = Ai::getInstance()->getSettings();
        $config = $settings->get('searchDrivers.opensearch');
        unset($config['class']);

        $this->client = ClientBuilder::fromConfig($config);

        return $this;
    }

    protected function ensureIndex(): self
    {
        $response = $this->client->indices()->resolveIndex(['name' => 'craft']);
        if (empty($response['indices'])) {
            $this->client->indices()->create(['index' => 'craft']);
        }

        return $this;
    }

    protected function ensureMapping(): self
    {
        // https://opensearch.org/docs/latest/search-plugins/knn/knn-index/
        $this->client->indices()->putMapping([
            'index' => 'craft',
            'body' => [
                'properties' => [
                    '_keywords_vec' => [
                        'type' => 'knn_vector',
                        'dimension' => 1536,
                        'index' => true,
                    ],
                ],
            ],
        ]);

        return $this;
    }

    /**
     * @param  array<mixed>  $document
     */
    public function index(string $id, array $document): self
    {
        $this->ensureIndex()->ensureMapping();

        $this->client->index(['index' => 'craft', 'id' => $id, 'body' => $document]);

        return $this;
    }

    /**
     * @param  array<double>  $vectors
     * @return array<mixed>
     */
    public function knnSearch(array $vectors, int $limit = 3): array
    {
        $json = $this->client->search([
            'index' => 'craft',
            'body' => [
                'size' => $limit,
                'query' => [
                    'script_score' => [
                        'query' => [
                            'bool' => [
                                'filter' => [
                                    'bool' => [
                                        'must_not' => [
                                            ['exists' => ['field' => 'revisionId']],
                                            ['exists' => ['field' => 'draftId']],
                                            ['exists' => ['field' => 'dateDeleted']],
                                        ],
                                        'must' => [
                                            'term' => ['enabled' => true],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'script' => [
                            'source' => 'knn_score',
                            'lang' => 'knn',
                            'params' => [
                                'field' => '_keywords_vec',
                                'query_value' => $vectors,
                                'space_type' => 'cosinesimil',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        /** @var array<mixed> $hits */
        $hits = $json['hits']['hits'];

        return [collect($hits), $json];
    }
}
