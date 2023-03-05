<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;
use craft\models\Volume;

class GenerateImagePostRequest extends Model
{
    public ?Backend $backend = null;
    public ?string $prompt = null;
    public ?Volume $volume = null;
    public ?int $count = 1;

    protected array $casts = [
        'backend' => ModelCast::class,
        'volume' => ModelCast::class,
    ];

    public function safeAttributes()
    {
        return array_merge(parent::safeAttributes(), ['backend', 'count']);
    }

    public function rules(): array
    {
        return [
            [['prompt', 'volume'], 'required'],
        ];
    }
}
