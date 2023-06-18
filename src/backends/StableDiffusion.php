<?php

namespace markhuot\craftai\backends;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\features\EditImage;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\validators\Json as JsonValidator;

class StableDiffusion extends \markhuot\craftai\models\Backend implements GenerateImage, EditImage
{
    use StableDiffusionGenerateImage;
    use StableDiffusionEditImage;

    protected array $defaultValues = [
        'type' => self::class,
        'name' => 'Stable Diffusion',
        'settings' => ['baseUrl' => 'https://api.stability.ai/v1alpha/'],
    ];

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl', 'apiKey'], 'required'],
            ]],
        ]);
    }

    public function handleErrorResponse(ClientException|ServerException $e): void
    {
        $response = json_decode($e->getResponse()->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        throw new \RuntimeException($response['message']);
    }
}
