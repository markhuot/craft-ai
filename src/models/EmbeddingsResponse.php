<?php

namespace markhuot\craftai\models;

use craft\base\Model;

class EmbeddingsResponse extends Model
{
    /** @var float[] */
    public array $vectors = [];

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['vectors', 'required'],
        ];
    }
}
