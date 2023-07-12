<?php

namespace markhuot\craftai\casts;

use markhuot\craftai\db\ActiveRecord;
use yii\base\Model;

interface CastInterface
{
    public function get(ActiveRecord|Model $model, string $key, mixed $value): mixed;

    public function set(ActiveRecord|Model $model, string $key, mixed $value): mixed;
}
