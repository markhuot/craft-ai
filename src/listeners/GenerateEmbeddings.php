<?php

namespace markhuot\craftai\listeners;

use craft\base\Element;
use craft\events\ModelEvent;
use craft\helpers\Search;
use Elastic\Elasticsearch\ClientBuilder;
use markhuot\craftai\actions\GetElementKeywords;
use markhuot\craftai\features\GenerateEmbeddings as GenerateEmbeddingsFeature;
use markhuot\craftai\models\Backend;

class GenerateEmbeddings
{
    function handle(ModelEvent $event)
    {
        /** @var Element $element */
        $element = $event->sender;

        $keywords = \Craft::$container->get(GetElementKeywords::class)
            ->handle($element)
            ->map(fn ($value, $key) => "The {$key} is {$value}")
            ->join("\n");

        $response = Backend::for(GenerateEmbeddingsFeature::class)
            ->generateEmbeddings($keywords);

        $document = [
            'id' => $element->id,
            'embeddings' => $response->vectors,
        ];

        $client = ClientBuilder::create()
            ->setHosts(['https://localhost:9200'])
            ->setBasicAuthentication('elastic', 'secret')
            //->setCABundle(\Craft::$app->path->getStoragePath() . '/certs/http_ca.crt')
            ->setSSLVerification(false)
            ->build();


        $response = json_decode((string)$client->indices()->resolveIndex(['name' => 'craft'])->getBody(), true, JSON_THROW_ON_ERROR);
        if  (empty($response['indices'])) {
            $client->indices()->create(['index' => 'craft']);
        }

        // https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-put-mapping.html
        // https://www.elastic.co/guide/en/elasticsearch/reference/current/dense-vector.html
        $response = $client->indices()->putMapping([
            'index' => 'craft',
            'body' => [
                'properties' => [
                    'embeddings' => [
                        'type' => 'dense_vector',
                        'dims' => 1024,
                        'index' => true,
                        'similarity' => 'l2_norm',
                    ],
                ],
            ],
        ]);

        $response = $client->index(['index' => 'craft', 'body' => $document]);

        dd((string)$response->getBody());
    }
}
