<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class TextCompletionResponse extends Model
{
    public ?string $text = '';

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['text', 'required'],
        ];
    }
}
