<?php

namespace markhuot\craftai\backends;

use markhuot\craftai\models\TextEditResponse;

trait OpenAiTextEdit
{
    public function editText(string $input, string $instruction): TextEditResponse
    {
        /** @var array{choices: array<array{text: string}>} $response */
        $response = $this->post('edits', [
            'model' => 'text-davinci-edit-001',
            'input' => strip_tags($input),
            'instruction' => $instruction,
        ]);

        $model = new TextEditResponse();
        $model->text = $response['choices'][0]['text'] ?? null;

        return $model;
    }

    /**
     * @return array<mixed>
     */
    public function editTextFake(string $input, string $instruction): array
    {
        return [
            'choices' => [
                [
                    'text' => $input.' '.$instruction,
                ],
            ],
        ];
    }
}
