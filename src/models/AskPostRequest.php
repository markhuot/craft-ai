<?php

namespace markhuot\craftai\models;

use markhuot\craftai\db\Model;

class AskPostRequest extends Model
{
    public string $prompt;

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            [['prompt'], 'required'],
        ];
    }
}
