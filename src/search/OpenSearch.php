<?php

namespace markhuot\craftai\search;

use markhuot\craftai\Ai;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

class OpenSearch
{
    protected Client $client;

    function __construct()
    {
        $this->connect();
    }

    protected function connect(): self
    {
        $config = Ai::getInstance()->getSettings();

        $this->client = (new ClientBuilder)
            ->setHosts($config->get('drivers.opensearch.hosts'))
            ->setBasicAuthentication($config->get('drivers.opensearch.basicAuthentication.0'), $config->get('drivers.opensearch.basicAuthentication.1'))
            //->setCABundle(\Craft::$app->path->getStoragePath() . '/certs/http_ca.crt')
            ->setSSLVerification($config->get('drivers.opensearch.SSLVerification'))
            ->build();

        return $this;
    }

    protected function ensureIndex(): self
    {
        $response = $this->client->indices()->resolveIndex(['name' => 'craft']);
        if  (empty($response['indices'])) {
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

    function index(string $id, array $document)
    {
        $this->ensureIndex()->ensureMapping();

        $this->client->index(['index' => 'craft', 'id' => $id, 'body' => $document]);
    }

    function knnSearch($vectors, $limit=3): array
    {
        $json = $this->client->search([
            'index' => 'craft',
            'body' => [
                "size" => $limit,
                "query" => [
                    "script_score" => [
                        "query" => [
                            "bool" => [
                                "filter" => [
                                    "bool" => [
                                        "must_not" => [
                                            ["exists" => ["field" => "revisionId"]],
                                            ["exists" => ["field" => "draftId"]],
                                            ["exists" => ["field" => "dateDeleted"]],
                                        ],
                                        "must" => [
                                            "term" => ["enabled" => true],
                                        ]
                                    ],
                                ],
                            ],
                        ],
                        "script" => [
                            "source" => "knn_score",
                            "lang" => "knn",
                            "params" => [
                                "field" => "_keywords_vec",
                                "query_value" => $vectors,
                                "space_type" => "cosinesimil",
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return [collect($json['hits']['hits']), $json];
    }
}
