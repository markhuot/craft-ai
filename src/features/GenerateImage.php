<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\ImageGenerationResponse;

interface GenerateImage
{
    function generateImage(string $prompt, int $count=1): ImageGenerationResponse;
}
