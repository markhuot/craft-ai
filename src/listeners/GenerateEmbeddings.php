<?php

namespace markhuot\craftai\listeners;

use craft\base\Element;
use craft\events\ModelEvent;
use Elastic\Elasticsearch\ClientBuilder;
use markhuot\craftai\actions\GetElementKeywords;
use markhuot\craftai\features\GenerateEmbeddings as GenerateEmbeddingsFeature;
use markhuot\craftai\models\Backend;
use markhuot\craftai\search\Search;

class GenerateEmbeddings
{
    function handle(
        ModelEvent $event,
        Search $search,
        GetElementKeywords $getKeywords,
    ): void {
        /** @var Element $element */
        $element = $event->sender;

        if ($element->getIsRevision() || $element->getIsDraft()) {
            return;
        }

        $keywords = $getKeywords->handle($element)
            ->map(fn ($value, $key) => "The {$key} is {$value}")
            ->join("\n");

        $response = Backend::for(GenerateEmbeddingsFeature::class)
            ->generateEmbeddings($keywords);

        $document = [
            'elementId' => $element->id,
            'elementType' => get_class($element),
            'revisionId' => $element->revisionId,
            'draftId' => $element->draftId,
            'dateDeleted' => $element->dateDeleted,
            'enabled' => $element->enabled,
            'title' => $element->title,
            '_keywords' => $keywords,
            '_keywords_vec' => $response->vectors,
        ];

        $id = implode('::', [get_class($element), $element->id]);
        $search->index($id, $document);
    }
}
