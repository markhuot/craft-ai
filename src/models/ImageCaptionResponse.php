<?php

namespace markhuot\craftai\models;

class ImageCaptionResponse
{
    public string $caption;

    public function rules(): array
    {
        return [
            ['caption', 'required'],
        ];
    }
}
