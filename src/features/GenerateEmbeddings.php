<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\EmbeddingsResponse;

interface GenerateEmbeddings
{
    public function generateEmbeddings(string $text): EmbeddingsResponse;
}
