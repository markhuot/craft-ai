<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\ChatMessageResponse;

trait OpenAiChat
{
    public function chatModel(): string
    {
        return ! empty($this->settings['chatModel']) ? $this->settings['chatModel'] : 'gpt-4';
    }
    public function chat(array $messages): ChatMessageResponse
    {
        /** @var array{choices: array<array{message: array{content: string}}>} $response */
        $response = $this->post('chat/completions', [
            'model' => $this->chatModel(),
            'messages' => $messages,
        ]);

        $model = new ChatMessageResponse;
        $model->message = $response['choices'][0]['message']['content'];

        return $model;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function chatFake(): array
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
