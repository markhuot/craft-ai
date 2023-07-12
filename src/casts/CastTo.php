<?php

namespace markhuot\craftai\casts;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class CastTo
{
    public function __construct(
        public string $className,
    ) {
    }
}
