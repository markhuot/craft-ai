<?php

namespace markhuot\craftai\attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Tool
{
    /**
     * @param  array<string, mixed>  $annotations  MCP tool annotations / hints (e.g. `destructiveHint`, `idempotentHint`, `readOnlyHint`, `openWorldHint`, `title`). Surfaced to MCP clients so harnesses can prompt for confirmation before invoking destructive tools.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly array $annotations = [],
    ) {}
}
