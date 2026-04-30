<?php

namespace markhuot\craftai\tools;

class ToolOutput
{
    public function __construct(
        public readonly string $text,
        public readonly bool $isError = false,
    ) {}
}
