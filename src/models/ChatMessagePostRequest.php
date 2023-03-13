<?php

namespace markhuot\craftai\models;

use markhuot\craftai\db\Model;

class ChatMessagePostRequest extends Model
{
    public string $message;

    public string $personality;

    public function rules(): array
    {
        return [
            [['personality'], 'required'],
            [['message'], 'required'],
        ];
    }
}
