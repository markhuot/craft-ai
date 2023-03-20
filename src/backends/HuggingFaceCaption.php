<?php

namespace markhuot\craftai\backends;

use craft\elements\Asset;
use markhuot\craftai\models\ImageCaptionResponse;

trait HuggingFaceCaption
{
    public function generateCaption(Asset $asset): ImageCaptionResponse
    {
        $body = $this->post(
            uri: 'nlpconnect/vit-gpt2-image-captioning',
            rawBody: $asset->getContents(),
        );

        $response = new ImageCaptionResponse;
        $response->caption = $body[0]['generated_text'];

        return $response;
    }
}
