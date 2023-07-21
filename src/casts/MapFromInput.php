<?php

namespace markhuot\craftai\casts;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class MapFromInput
{
    public function __construct(
        public string $inputKey,
    ) {
    }
}
