<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class ImageCaptionResponse extends Model
{
    public string $caption;

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['caption', 'required'],
        ];
    }
}
