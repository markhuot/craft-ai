<?php

namespace markhuot\craftai\tests\factories;

use markhuot\craftai\backends\OpenAi as OpenAiModel;
use markhuot\craftpest\factories\Factory;

class OpenAi extends Factory
{
    function definition(int $index = 0)
    {
        return [
            'type' => OpenAiModel::class,
            'name' => 'OpenAI Backend',
            'settings' => ['baseUrl' => 'https://api.openai.com/v1/', 'apiKey' => '$OPENAI_API_KEY'],
        ];
    }

    function newElement()
    {
        return new OpenAiModel;
    }

    function store($element)
    {
        return $element->save();
    }
}
