<?php

namespace markhuot\craftai\models;

use craft\models\Volume;
use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;

class GenerateImagePostRequest extends Model
{
    public ?Backend $backend = null;

    public ?string $prompt = null;

    public ?Volume $volume = null;

    public ?int $count = 1;

    /** @var array<string, class-string> */
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
