<?php

namespace markhuot\craftai\backends;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\features\Caption;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\models\Backend;
use markhuot\craftai\validators\Json as JsonValidator;
use RuntimeException;

/**
 * @property array{enabledFeatures: string[], baseUrl: string, apiKey: string, textGenerationModel: ?string, imageToTextModel: ?string, textToImageModel: ?string } $settings
 */
class HuggingFace extends Backend implements Caption, Completion, GenerateImage
{
    use HuggingFaceCaption,
        HuggingFaceCompletion,
        HuggingFaceGenerateImage;

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

    public function getTextGenerationModel(): string
    {
        return ! empty($this->settings['textGenerationModel']) ? $this->settings['textGenerationModel'] : 'tiiuae/falcon-7b-instruct';
    }

    public function getImageToTextModel(): string
    {
        return ! empty($this->settings['imageToTextModel']) ? $this->settings['imageToTextModel'] : 'nlpconnect/vit-gpt2-image-captioning';
    }

    public function getTextToImageModel(): string
    {
        return ! empty($this->settings['textToImageModel']) ? $this->settings['textToImageModel'] : 'stabilityai/stable-diffusion-2-1';
    }

    public function handleErrorResponse(ClientException|ServerException $e): never
    {
        /** @var array{error: ?string[]} $response */
        $response = json_decode($e->getResponse()->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        throw new RuntimeException(implode('. ', ($response['error'] ?? ['Unknown Error'])));
    }
}
