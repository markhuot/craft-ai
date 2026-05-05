<?php

namespace markhuot\craftai\attributes;

use Attribute;

/**
 * Attaches a Yii validation rule to a tool parameter. The first argument is
 * either a built-in Yii validator alias (e.g. "string", "required", "match")
 * or a fully-qualified validator class name. Remaining named arguments are
 * passed through to the rule as configuration options.
 *
 * Conditional rules: each `whenX` argument names a sibling parameter and the
 * condition that must hold for the rule to apply. Multiple conditions on one
 * attribute are AND-combined; for OR semantics, repeat the attribute. Use
 * these instead of branching inside __invoke so the validator can collect
 * every error into a single response.
 *
 *   - `whenMissing: 'id'` — apply when sibling `id` is empty/null
 *   - `whenPresent: 'id'` — apply when sibling `id` is non-empty
 *   - `whenIsA: ['type' => Foo::class, 'id' => Foo::class]` — apply when ANY
 *     listed sibling is_a the paired class (OR across entries; works for both
 *     class-string values and bound objects). For AND, use multiple attributes.
 *
 * Example:
 *   #[Validate('string', max: 255)]
 *   #[Validate('required', whenMissing: 'id')]
 *   #[Validate(ExistingSection::class)]
 *   #[Validate(AssetSettingsValidation::class, whenIsA: ['type' => Assets::class])]
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class Validate
{
    /** @var array<string, mixed> */
    public readonly array $options;

    /** @var array<string, class-string> */
    public readonly array $whenIsA;

    /**
     * @param  array<string, class-string>  $whenIsA
     */
    public function __construct(
        public readonly string $rule,
        public readonly ?string $whenMissing = null,
        public readonly ?string $whenPresent = null,
        array $whenIsA = [],
        mixed ...$options,
    ) {
        $this->whenIsA = $whenIsA;
        /** @var array<string, mixed> $options */
        $this->options = $options;
    }
}
