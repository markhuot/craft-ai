<?php

namespace markhuot\craftai\backends;

use Faker\Factory;
use markhuot\craftai\models\TextCompletionResponse;

trait OpenAiCompletion
{
    public function completionModel(): string
    {
        return ! empty($this->settings['completionModel']) ? $this->settings['completionModel'] : 'text-davinci-003';
    }

    public function completeText(string $text): TextCompletionResponse
    {
        /** @var array{choices: array<array{text: string}>} $response */
        $response = $this->post('completions', [
            'model' => $this->completionModel(),
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

    /**
     * @return array<mixed>
     */
    public function completeTextFake(string $text): array
    {
        return [
            'choices' => [
                [
                    'text' => Factory::create()->paragraph(5),
                ],
            ],
        ];
    }
}
