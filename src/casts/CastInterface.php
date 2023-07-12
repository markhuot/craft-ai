<?php

namespace markhuot\craftai\casts;

use markhuot\craftai\db\ActiveRecord;

interface CastInterface
{
    public function get(ActiveRecord $model, string $key, mixed $value): mixed;

    public function set(ActiveRecord $model, string $key, mixed $value): mixed;
}
