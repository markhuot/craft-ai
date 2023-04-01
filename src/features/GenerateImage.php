<?php

namespace markhuot\craftai\features;

use markhuot\craftai\models\ImageGenerationResponse;

interface GenerateImage
{
    public function generateImage(string $prompt, int $count = 1): ImageGenerationResponse;
}
