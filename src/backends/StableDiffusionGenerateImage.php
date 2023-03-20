<?php

namespace markhuot\craftai\backends;

use craft\helpers\Assets;
use markhuot\craftai\models\ImageGenerationResponse;

trait StableDiffusionGenerateImage
{
    public function generateImage(string $prompt, int $count = 1): ImageGenerationResponse
    {
        $body = $this->post(
            uri: 'generation/stable-diffusion-512-v2-1/text-to-image',
            headers: [
                'Accept' => 'application/json',
            ],
            body: [
                'text_prompts' => [['text' => $prompt]],
                'samples' => $count,
            ],
        );

        $paths = [];
        foreach ($body['artifacts'] as $artifact) {
            $contents = base64_decode($artifact['base64']);
            $tmp = Assets::tempFilePath('png');
            file_put_contents($tmp, $contents);
            $paths[] = $tmp;
        }

        $response = new ImageGenerationResponse;
        $response->paths = $paths;

        return $response;
    }
}
