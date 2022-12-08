<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;
use craft\models\Volume;

class GenerateImagePostRequest extends Model
{
    public ?string $prompt = null;
    public ?Volume $volume = null;

    protected array $casts = [
        'volume' => ModelCast::class,
    ];

    public function rules(): array
    {
        return [
            [['prompt', 'volume'], 'required'],
        ];
    }
}
