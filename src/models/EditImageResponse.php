<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class EditImageResponse extends Model
{
    /** @var string[] */
    public array $paths = [];

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['paths', 'required'],
        ];
    }
}
