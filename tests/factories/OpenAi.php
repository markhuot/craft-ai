<?php

namespace markhuot\craftai\tests\factories;

use markhuot\craftai\backends\OpenAi as OpenAiModel;
use markhuot\craftai\features\Chat;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\EditImage;
use markhuot\craftai\features\EditText;
use markhuot\craftai\features\GenerateEmbeddings;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftpest\factories\Factory;

class OpenAi extends Factory
{
    public function definition(int $index = 0)
    {
        return [
            'type' => OpenAiModel::class,
            'name' => 'OpenAI Backend',
            'settings' => [
                'baseUrl' => 'https://api.openai.com/v1/',
                'apiKey' => '$OPENAI_API_KEY',
                'enabledFeatures' => [
                    Chat::class,
                    Completion::class,
                    EditImage::class,
                    EditText::class,
                    GenerateEmbeddings::class,
                    GenerateImage::class,
                ],
            ],
        ];
    }

    public function newElement()
    {
        return new OpenAiModel;
    }

    public function store($element)
    {
        return $element->save();
    }
}
