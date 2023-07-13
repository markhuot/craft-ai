<?php

namespace markhuot\craftai\models;

use markhuot\craftai\casts\Model as ModelCast;
use markhuot\craftai\db\Model;

class BackendDeleteRequest extends Model
{
    public ?Backend $backend = null;

    protected array $casts = [
        'backend' => ModelCast::class,
    ];

    /**
     * @return array<array-key, mixed>
     */
    public function rules(): array
    {
        return [
            ['backend', 'required'],
        ];
    }
}
