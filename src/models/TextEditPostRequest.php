<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class TextEditPostRequest extends Model
{
    public ?string $input = null;

    public ?string $instructions = null;

    public function rules(): array
    {
        return [
            ['input', 'required'],
            ['instructions', 'required'],
        ];
    }
}
