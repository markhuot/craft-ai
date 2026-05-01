<?php

namespace markhuot\craftai\binders;

interface Binder
{
    /**
     * JSON schema fragment describing the value the LLM should pass for the
     * bound parameter (e.g. ['type' => 'string']).
     *
     * @return array<string, mixed>
     */
    public function sourceSchema(): array;

    /**
     * Resolve the raw input value into the parameter's runtime type. Receives
     * the full named-arguments array so cross-field binders (e.g. an entry
     * type within a section) can read sibling values.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function bind(mixed $value, array $arguments): mixed;
}
