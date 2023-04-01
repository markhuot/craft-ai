<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\ImageGenerationResponse;

trait HuggingFaceStableDiffusion
{
    public function generateImage(string $prompt, int $count = 1): ImageGenerationResponse
    {
        $body = $this->post(
            uri: 'stabilityai/stable-diffusion-2-1',
            body: [
                'inputs' => $prompt,
            ],
        );

        $paths = [];
        foreach ($body['data'] as $image) {
            $tmp = Assets::tempFilePath('png');
            file_put_contents($tmp, file_get_contents($image['url']));
            $paths[] = $tmp;
        }

        return new ImageGenerationResponse([
            'images' => $paths,
        ]);
    }
}
