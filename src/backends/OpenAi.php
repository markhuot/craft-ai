<?php

namespace markhuot\craftai\backends;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use markhuot\craftai\features\Chat;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\Edit;
use markhuot\craftai\features\EditImage;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\validators\Json as JsonValidator;
use RuntimeException;

class OpenAi extends \markhuot\craftai\models\Backend implements Completion, Edit, GenerateImage, Chat, EditImage
{
    use OpenAiCompletion;
    use OpenAiTextEdit;
    use OpenAiDalle;
    use OpenAiChat;
    use OpenAiEditImage;

    protected array $defaultValues = [
        'name' => 'OpenAI',
        'settings' => ['baseUrl' => 'https://api.openai.com/v1/'],
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
        throw new RuntimeException($response['error']['message'] ?? 'Unknown API error');
    }
}
