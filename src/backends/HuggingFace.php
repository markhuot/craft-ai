<?php

namespace markhuot\craftai\backends;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\features\Caption;
use markhuot\craftai\models\Backend;
use markhuot\craftai\validators\Json as JsonValidator;
use RuntimeException;

class HuggingFace extends Backend implements Caption
{
    use HuggingFaceCaption;

    protected array $defaultValues = [
        'type' => self::class,
        'name' => 'Hugging Face',
        'settings' => ['baseUrl' => 'https://api-inference.huggingface.co/models/'],
    ];

    /**
     * @return array<mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            ['settings', 'required'],
            ['settings', JsonValidator::class, 'rules' => [
                [['baseUrl', 'apiKey'], 'required'],
            ]],
        ]);
    }

    public function handleErrorResponse(ClientException|ServerException $e): never
    {
        /** @var array{error: ?string} $response */
        $response = json_decode($e->getResponse()->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        throw new RuntimeException($response['error'] ?? 'Unknown API error');
    }
}
