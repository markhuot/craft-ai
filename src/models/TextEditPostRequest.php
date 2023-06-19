<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class TextEditPostRequest extends Model
{
    public string $input;

    public string $instructions;

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['input', 'required'],
            ['instructions', 'required'],
        ];
    }
}
