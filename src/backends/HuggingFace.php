<?php

namespace markhuot\craftai\backends;

use craft\elements\Asset;
use craft\helpers\Assets;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\features\Caption;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\ImageCaptionResponse;
use markhuot\craftai\models\ImageGenerationResponse;
use markhuot\craftai\validators\Json as JsonValidator;
use RuntimeException;

class HuggingFace extends Backend implements Caption
{
    protected array $defaultValues = [
        'name' => 'Hugging Face',
        'settings' => ['baseUrl' => 'https://api-inference.huggingface.co/models/'],
    ];

    public function rules()
    {
        return array_merge(parent::rules(), [
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl', 'apiKey'], 'required'],
            ]]
        ]);
    }

    public function handleErrorResponse(ClientException|ServerException $e)
    {
        $response = json_decode($e->getResponse()->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        throw new RuntimeException($response['error'] ?? 'Unknown API error');
    }

    /**
     * @todo, this always says "model loading" need to figure out why
     */
    // public function generateImage(string $prompt): ImageGenerationResponse
    // {
    //     $body = $this->post(
    //         uri: 'CompVis/stable-diffusion-v1-4',
    //         body: [
    //             'inputs' => $prompt,
    //         ],
    //     );
    //     dd($body);
    //
    //     $paths = [];
    //     foreach ($body['data'] as $image) {
    //         $tmp = Assets::tempFilePath('png');
    //         file_put_contents($tmp, file_get_contents($image['url']));
    //         $paths[] = $tmp;
    //     }
    //
    //     $response = new ImageGenerationResponse;
    //     $response->paths = $paths;
    //
    //     return $response;
    // }

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
