<?php

namespace markhuot\craftai\backends;

use Faker\Factory;
use markhuot\craftai\features\Completion;
use markhuot\craftai\models\TextCompletionResponse;

trait OpenAiCompletion
{
    function completeText(string $text): TextCompletionResponse
    {
        $response = $this->post('completions', [
            'model' => 'text-davinci-003',
            'prompt' => strip_tags($text),
            'temperature' => 0.7,
            'max_tokens' => 256,
            'top_p' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);

        $model = new TextCompletionResponse;
        $model->text = $response['choices'][0]['text'] ?? null;

        return $model;
    }

    function completeTextFake(string $text): array
    {
        return [
            'choices' => [
                [
                    'text' => $text . Factory::create()->paragraph(5),
                ],
            ],
        ];
    }
}
