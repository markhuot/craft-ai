<?php

namespace markhuot\craftai\backends;

use craft\elements\Asset;
use craft\helpers\Assets;
use markhuot\craftai\models\EditImageResponse;

trait OpenAiEditImage
{
    public function editImage(string $prompt, Asset $asset, string $mask, int $count = 1): EditImageResponse
    {
        /** @var array{data: array<array{url: string}>} $body */
        $body = $this->post(
            uri: 'images/edits',
            multipart: [
                ['name' => 'image', 'contents' => $asset->getContents(), 'filename' => 'original.png'],
                ['name' => 'mask', 'contents' => base64_decode(str_replace(' ', '+', explode('base64,', $mask)[1])), 'filename' => 'mask.png'],
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

    /**
     * @return array<mixed>
     */
    public function editImageFake(string $prompt, Asset $asset, string $mask, int $count = 1): array
    {
        return [
            'data' => [
                [
                    'url' => __DIR__.'/../../tests/data/fake.png',
                ],
            ],
        ];
    }
}
