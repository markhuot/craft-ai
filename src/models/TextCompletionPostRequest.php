<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class TextCompletionPostRequest extends Model
{
    public string $content;

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['content', 'required'],
        ];
    }
}
