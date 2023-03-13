<?php

namespace markhuot\craftai\backends;

use craft\helpers\Assets;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\models\ImageGenerationResponse;
use markhuot\craftpest\factories\Asset;

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

    function generateImageFake(string $prompt, int $count=1): array
    {
        if ($prompt === 'ERROR') {
            throw new ClientException(
                message: 'faked error',
                request: new Request('GET', 'image/generate'),
                response: new Response(500, [], json_encode(['error' => ['message' => 'faked error']])),
            );
        }

        return [
            'data' => [
                [
                    'url' => __DIR__.'/../../tests/data/fake.png',
                ],
            ],
        ];
    }
}
