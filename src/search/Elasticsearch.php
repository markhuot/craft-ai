<?php

namespace markhuot\craftai\search;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use markhuot\craftai\Ai;

class Elasticsearch
{
    protected Client $client;

    function __construct()
    {
        $this->connect();
    }

    protected function connect(): self
    {
        $config = Ai::getInstance()->getSettings();

        $this->client = ClientBuilder::create()
            ->setHosts($config->get('drivers.elasticsearch.hosts'))
            ->setBasicAuthentication($config->get('drivers.elasticsearch.basicAuthentication.0'), $config->get('drivers.elasticsearch.basicAuthentication.1'))
            //->setCABundle(\Craft::$app->path->getStoragePath() . '/certs/http_ca.crt')
            ->setSSLVerification($config->get('drivers.elasticsearch.SSLVerification'))
            ->build();

        return $this;
    }

    protected function ensureIndex(): self
    {
        $response = json_decode((string)$this->client->indices()->resolveIndex(['name' => 'craft'])->getBody(), true, JSON_THROW_ON_ERROR);
        if  (empty($response['indices'])) {
            $this->client->indices()->create(['index' => 'craft']);
        }

        return $this;
    }

    protected function ensureMapping(): self
    {
        // https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-put-mapping.html
        // https://www.elastic.co/guide/en/elasticsearch/reference/current/dense-vector.html
        $response = $this->client->indices()->putMapping([
            'index' => 'craft',
            'body' => [
                'properties' => [
                    'embeddings' => [
                        'type' => 'dense_vector',
                        'dims' => 1536,
                        'index' => true,
                        'similarity' => 'l2_norm',
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

    function knnSearch($vectors): array
    {
        $response = $this->client->search([
            'index' => 'craft',
            'body' => [
                'knn' => [
                    'field' => 'embeddings',
                    'query_vector' => $vectors,
                    'k' => 10,
                    'num_candidates' => 100,
                    "filter" => [
                        "bool" => [
                            "must_not" => [
                                ["exists" => ["field" => "revisionId"]],
                                ["exists" => ["field" => "draftId"]],
                                ["exists" => ["field" => "dateDeleted"]],
                            ],
                        ],
                    ],
                ],
                'fields' => ['elementId', 'elementType'],
                // '_source' => false,
            ],
        ])->getBody();

        $json = json_decode((string)$response, true, JSON_THROW_ON_ERROR);
        dd($json);
    }
}
