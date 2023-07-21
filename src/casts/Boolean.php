<?php

namespace markhuot\craftai\casts;

use markhuot\craftai\db\ActiveRecord;
use function markhuot\openai\helpers\throw_if;

class Boolean implements CastInterface
{
    public function get(ActiveRecord|\yii\base\Model $model, string $key, mixed $value): mixed
    {
        return (bool) $value;
    }

    public function set(ActiveRecord|\yii\base\Model $model, string $key, mixed $value): mixed
    {
        return $value ? 1 : 0;
    }
}
