<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\ChatMessageResponse;

trait OpenAiChat
{
    function chat(array $messages): ChatMessageResponse
    {
        $response = $this->post('chat/completions', [
            "model" => "gpt-3.5-turbo",
            'messages' => $messages,
        ]);

        $model = new ChatMessageResponse;
        $model->message = $response['choices'][0]['message']['content'];

        return $model;
    }
}
