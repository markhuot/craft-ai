<?php

namespace markhuot\craftai\backends;

use craft\helpers\Assets;
use markhuot\craftai\models\ImageGenerationResponse;

trait OpenAiDalle
{
    function generateImage(string $prompt, int $count=1): ImageGenerationResponse
    {
        $body = $this->post(
            uri: 'images/generations',
            body: [
                'prompt' => $prompt,
                'n' => $count,
                'size' => '512x512'
            ],
        );

        $paths = [];
        foreach ($body['data'] as $image) {
            $tmp = Assets::tempFilePath('png');
            file_put_contents($tmp, file_get_contents($image['url']));
            $paths[] = $tmp;
        }

        $response = new ImageGenerationResponse;
        $response->paths = $paths;

        return $response;
    }
}
