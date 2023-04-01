<?php

namespace markhuot\craftai\models;

use markhuot\craftai\db\Model;

class AskPostRequest extends Model
{
    public string $prompt;

    public function rules(): array
    {
        return [
            [['prompt'], 'required'],
        ];
    }
}
