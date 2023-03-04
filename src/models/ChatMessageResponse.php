<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class ChatMessageResponse extends Model
{
    public string $message;

    public function rules(): array
    {
        return [
            ['message', 'required'],
        ];
    }
}
