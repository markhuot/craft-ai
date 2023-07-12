<?php

namespace markhuot\craftai\models;

use craft\elements\Asset;
use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;
use markhuot\craftai\features\EditImage;

class EditImagePostRequest extends Model
{
    public string $prompt;

    public Asset $asset;

    public string $mask;

    public int $count = 1;

    public EditImage|null $backend = null;

    protected array $casts = [
        'asset' => ModelCast::class,
        'backend' => ModelCast::class,
    ];

    public function safeAttributes()
    {
        return array_merge(parent::safeAttributes(), ['backend']);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            [['prompt', 'asset', 'mask'], 'required'],
        ];
    }
}
