<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class ChatMessageResponse extends Model
{
    public string $message;

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['message', 'required'],
        ];
    }
}
