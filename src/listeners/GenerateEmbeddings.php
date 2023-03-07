<?php

namespace markhuot\craftai\listeners;

use craft\events\IndexKeywordsEvent;
use markhuot\craftai\features\GenerateEmbeddings as GenerateEmbeddingsFeature;
use markhuot\craftai\models\Backend;

class GenerateEmbeddings
{
    function handle(IndexKeywordsEvent $event)
    {
        $response = Backend::for(GenerateEmbeddingsFeature::class)
            ->generateEmbeddings($event->keywords);
    }
}
