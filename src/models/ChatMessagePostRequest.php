<?php

namespace markhuot\craftai\models;

use markhuot\craftai\db\Model;

class ChatMessagePostRequest extends Model
{
    public ?string $message = null;

    public ?string $personality = null;

    public ?int $elementId = null;

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            [['message'], 'required'],
        ];
    }

    /**
     * @return array<array-key, string>
     */
    public function safeAttributes(): array
    {
        return array_merge(parent::safeAttributes(), ['personality', 'elementId']);
    }
}
