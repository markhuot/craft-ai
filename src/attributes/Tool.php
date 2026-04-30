<?php

namespace markhuot\craftai\attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Tool
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
    ) {}
}
