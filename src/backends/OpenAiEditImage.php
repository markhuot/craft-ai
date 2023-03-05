<?php

namespace markhuot\craftai\backends;

use craft\elements\actions\Edit;
use craft\elements\Asset;
use craft\helpers\Assets;
use markhuot\craftai\models\EditImagePostRequest;
use markhuot\craftai\models\EditImageResponse;
use markhuot\craftai\models\ImageGenerationResponse;

trait OpenAiEditImage
{
    function editImage(string $prompt, Asset $asset, string $mask, int $count=1): EditImageResponse
    {
        $body = $this->post(
            uri: 'images/edits',
            multipart: [
                ['name' => 'image', 'contents' => $asset->getContents(), 'filename' => 'original.png'],
                ['name' => 'mask', 'contents' => base64_decode(str_replace(' ','+', explode('base64,', $mask)[1])), 'filename' => 'mask.png'],
                ['name' => 'prompt', 'contents' => $prompt],
                ['name' => 'n', 'contents' => 1],
                ['name' => 'size', 'contents' => '512x512'],
            ],
        );

        $paths = [];
        foreach ($body['data'] as $image) {
            $tmp = Assets::tempFilePath('png');
            file_put_contents($tmp, file_get_contents($image['url']));
            $paths[] = $tmp;
        }

        $response = new EditImageResponse;
        $response->paths = $paths;

        return $response;
    }
}
