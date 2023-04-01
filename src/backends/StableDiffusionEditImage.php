<?php

namespace markhuot\craftai\backends;

use craft\elements\Asset;
use craft\helpers\Assets;
use markhuot\craftai\models\EditImageResponse;

trait StableDiffusionEditImage
{
    public function editImage(string $prompt, Asset $asset, string $mask, int $count = 1): EditImageResponse
    {
        $body = $this->post(
            uri: 'generation/stable-diffusion-512-v2-1/image-to-image/masking',
            headers: [
                'Accept' => 'application/json',
            ],
            multipart: [
                ['name' => 'init_image', 'contents' => base64_decode(str_replace(' ', '+', explode('base64,', $mask)[1])), 'filename' => 'original.png'],
                ['name' => 'options', 'contents' => json_encode(['mask_source' => 'INIT_IMAGE_ALPHA'])],
                ['name' => 'text_prompts[0][text]', 'contents' => $prompt],
            ],
        );

        $paths = [];
        foreach ($body['artifacts'] as $artifact) {
            $contents = base64_decode($artifact['base64']);
            $tmp = Assets::tempFilePath('png');
            file_put_contents($tmp, $contents);
            $paths[] = $tmp;
        }

        $response = new EditImageResponse;
        $response->paths = $paths;

        return $response;
    }
}
