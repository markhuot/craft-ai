<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class ImageGenerationResponse extends Model
{
    /** @var string[] */
    public array $paths = [];

    public function rules(): array
    {
        return [
            ['paths', 'required'],
        ];
    }
}
