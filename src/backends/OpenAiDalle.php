<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\ImageGenerationResponse;

trait OpenAiDalle
{
    function generateImage(string $prompt): ImageGenerationResponse
    {
        return new ImageGenerationResponse;
    }
}
