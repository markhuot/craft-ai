<?php

namespace markhuot\craftai\models;

use craft\models\Volume;
use markhuot\craftai\casts\CastTo;
use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;
use markhuot\craftai\features\GenerateImage;

class GenerateImagePostRequest extends Model
{
    #[CastTo(Backend::class)]
    public ?GenerateImage $backend = null;

    public string $prompt;

    public Volume $volume;

    public int $count = 1;

    protected array $casts = [
        'backend' => ModelCast::class,
        'volume' => ModelCast::class,
    ];

    public function safeAttributes()
    {
        return array_merge(parent::safeAttributes(), ['backend', 'count']);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            [['prompt', 'volume'], 'required'],
        ];
    }
}
