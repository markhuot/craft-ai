<?php

namespace markhuot\craftai\casts;

interface CastInterface
{
    public function get($model, $key, $value);
    public function set($model, $key, $value);
}
