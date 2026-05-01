<?php

namespace markhuot\craftai\attributes;

use Attribute;

/**
 * Attaches a Yii validation rule to a tool parameter. The first argument is
 * either a built-in Yii validator alias (e.g. "string", "required", "match")
 * or a fully-qualified validator class name. Remaining named arguments are
 * passed through to the rule as configuration options.
 *
 * Example:
 *   #[Validate('string', max: 255)]
 *   #[Validate(ExistingSection::class)]
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class Validate
{
    /** @var array<string, mixed> */
    public readonly array $options;

    public function __construct(
        public readonly string $rule,
        mixed ...$options,
    ) {
        /** @var array<string, mixed> $options */
        $this->options = $options;
    }
}
