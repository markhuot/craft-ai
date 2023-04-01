<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\ChatMessageResponse;

trait OpenAiChat
{
    public function chat(array $messages): ChatMessageResponse
    {
        $response = $this->post('chat/completions', [
            'model' => 'gpt-4',
            'messages' => $messages,
        ]);

        $model = new ChatMessageResponse;
        $model->message = $response['choices'][0]['message']['content'];

        return $model;
    }

    public function chatFake(array $messages): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $this->faker->sentence,
                    ],
                ],
            ],
        ];
    }
}
