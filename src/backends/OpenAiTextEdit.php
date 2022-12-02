<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\TextEditResponse;

trait OpenAiTextEdit
{
    function editText(string $input, string $instruction): TextEditResponse
    {
        $response = $this->post('edits', [
            'model' => 'text-davinci-edit-001',
            'input' => strip_tags($input),
            'instruction' => $instruction,
        ]);

        $model = new TextEditResponse();
        $model->text = $response['choices'][0]['text'] ?? null;

        return $model;
    }
}
