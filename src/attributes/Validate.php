<?php

namespace markhuot\craftai\attributes;

use Attribute;

/**
 * Attaches a Yii validation rule to a tool parameter. The first argument is
 * either a built-in Yii validator alias (e.g. "string", "required", "match")
 * or a fully-qualified validator class name. Remaining named arguments are
 * passed through to the rule as configuration options.
 *
 * Conditional rules: pass `whenMissing` or `whenPresent` with the name of a
 * sibling parameter to apply the rule only when that sibling is empty/non-empty.
 * Use these instead of branching inside __invoke so the validator can collect
 * every error into a single response.
 *
 * Example:
 *   #[Validate('string', max: 255)]
 *   #[Validate('required', whenMissing: 'id')]
 *   #[Validate(ExistingSection::class)]
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class Validate
{
    /** @var array<string, mixed> */
    public readonly array $options;

    public function __construct(
        public readonly string $rule,
        public readonly ?string $whenMissing = null,
        public readonly ?string $whenPresent = null,
        mixed ...$options,
    ) {
        /** @var array<string, mixed> $options */
        $this->options = $options;
    }
}
