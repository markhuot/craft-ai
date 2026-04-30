<?php

namespace markhuot\craftai\attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
class Description
{
    public function __construct(
        public readonly string $value,
    ) {}
}
