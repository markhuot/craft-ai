<?php

namespace markhuot\craftai\models;

use markhuot\craftai\db\Model;

class ChatMessagePostRequest extends Model
{
    public ?string $message = null;

    public ?string $personality = null;

    public ?int $elementId = null;

    public function rules(): array
    {
        return [
            [['message'], 'required'],
        ];
    }

    public function safeAttributes()
    {
        return array_merge(parent::safeAttributes(), ['personality', 'elementId']);
    }
}
