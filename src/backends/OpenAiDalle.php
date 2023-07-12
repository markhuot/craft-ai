<?php

namespace markhuot\craftai\backends;

use craft\helpers\Assets;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\models\ImageGenerationResponse;

trait OpenAiDalle
{
    public function generateImage(string $prompt, int $count = 1): ImageGenerationResponse
    {
        /** @var array{data: array<array{url: string}>} $body */
        $body = $this->post(
            uri: 'images/generations',
            body: [
                'prompt' => $prompt,
                'n' => $count,
                'size' => '512x512',
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

    /**
     * @return array<mixed>
     */
    public function generateImageFake(string $prompt, int $count = 1): array
    {
        if ($prompt === 'ERROR') {
            throw new ClientException(
                message: 'faked error',
                request: new Request('GET', 'image/generate'),
                response: new Response(500, [], json_encode(['error' => ['message' => 'faked error']], JSON_THROW_ON_ERROR)),
            );
        }

        return [
            'data' => collect([])
                ->pad($count, null)
                ->map(fn () => ['url' => __DIR__.'/../../tests/data/fake.png'])
                ->toArray(),
        ];
    }
}
