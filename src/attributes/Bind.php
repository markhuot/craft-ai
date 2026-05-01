<?php

namespace markhuot\craftai\attributes;

use Attribute;

/**
 * Marks a tool parameter for binding: the LLM passes a scalar (e.g. a handle)
 * which the named Binder resolves into a richer value (e.g. a model) before
 * the tool's __invoke is called. Declare the wire-level shape directly on the
 * parameter's type union (e.g. Section|string|int) — the schema builder strips
 * object variants so the LLM only sees the scalar forms.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Bind
{
    /** @var array<string, mixed> */
    public readonly array $options;

    /**
     * @param  class-string  $binder
     */
    public function __construct(
        public readonly string $binder,
        mixed ...$options,
    ) {
        /** @var array<string, mixed> $options */
        $this->options = $options;
    }
}
