<?php

namespace markhuot\craftai\backends;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\features\Chat;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\EditImage;
use markhuot\craftai\features\EditText;
use markhuot\craftai\features\GenerateEmbeddings;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\validators\Json as JsonValidator;
use RuntimeException;

/**
 * @property array{enabledFeatures: string[], baseUrl: string, apiKey: string, completionModel: string, chatModel: string} $settings
 */
class OpenAi extends \markhuot\craftai\models\Backend implements Completion, EditText, GenerateImage, Chat, EditImage, GenerateEmbeddings
{
    use OpenAiCompletion;
    use OpenAiTextEdit;
    use OpenAiDalle;
    use OpenAiChat;
    use OpenAiEditImage;
    use OpenAiGenerateEmbeddings;

    protected array $defaultValues = [
        'type' => self::class,
        'name' => 'OpenAI',
        'settings' => ['baseUrl' => 'https://api.openai.com/v1/'],
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
        /** @var array{error: array{message: ?string}|null} $response */
        $response = json_decode($e->getResponse()->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        throw new RuntimeException($response['error']['message'] ?? 'Unknown API error');
    }
}
