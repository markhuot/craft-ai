<?php

namespace markhuot\craftai\backends;

use craft\elements\Asset;
use markhuot\craftai\models\ImageCaptionResponse;

trait HuggingFaceCaption
{
    public function generateCaption(Asset $asset): ImageCaptionResponse
    {
        /** @var array<array{generated_text: string}> $body */
        $body = $this->post(
            uri: $this->getImageToTextModel(),
            rawBody: $asset->getContents(),
        );

        $response = new ImageCaptionResponse;
        $response->caption = $body[0]['generated_text'];

        return $response;
    }

    /**
     * @return array<mixed>
     */
    public function generateCaptionFake(Asset $asset): array
    {
        return [
            ['generated_text' => 'lorem ipsum dolor sit amet'],
        ];
    }
}
