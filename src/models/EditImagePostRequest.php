<?php

namespace markhuot\craftai\models;

use craft\elements\Asset;
use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;

class EditImagePostRequest extends Model
{
    public ?string $prompt = null;

    public ?Asset $asset = null;

    public ?string $mask = null;

    public ?int $count = 1;

    protected array $casts = [
        'asset' => ModelCast::class,
    ];

    public function rules(): array
    {
        return [
            [['prompt', 'asset', 'mask'], 'required'],
        ];
    }
}
