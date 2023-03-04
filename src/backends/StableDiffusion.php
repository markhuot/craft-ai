<?php

namespace markhuot\craftai\backends;

use craft\helpers\Assets;
use Gooseai\ArtifactType;
use Gooseai\ClassifierParameters;
use Gooseai\GenerationServiceClient;
use Gooseai\Prompt;
use Gooseai\Request;
use GPBMetadata\Generation;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\models\ImageGenerationResponse;
use markhuot\craftai\validators\Json as JsonValidator;

class StableDiffusion extends \markhuot\craftai\models\Backend implements GenerateImage
{
    protected array $defaultValues = [
        'name' => 'Stable Diffusion',
        'settings' => ['baseUrl' => 'https://api.stability.ai/v1alpha/'],
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
        throw new \RuntimeException($response['message']);
    }

    function generateImage(string $prompt): ImageGenerationResponse
    {
        $body = $this->post(
            uri: 'generation/stable-diffusion-512-v2-1/text-to-image',
            headers: [
                'Accept' => 'application/json',
            ],
            body: [
                'text_prompts' => [['text' => $prompt]],
                'samples' => 2,
            ],
        );

        // $body = json_decode(file_get_contents(__DIR__.'/../../tests/responses/stablediffusion/text-to-image.json'), true, 512, JSON_THROW_ON_ERROR);

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
