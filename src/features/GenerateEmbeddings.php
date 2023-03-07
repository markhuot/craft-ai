<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\EmbeddingsResponse;

interface GenerateEmbeddings
{
    function generateEmbeddings(string $text): EmbeddingsResponse;
}
