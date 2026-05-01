<?php

namespace markhuot\craftai\tools;

use markhuot\craftai\attributes\Validate;
use ReflectionMethod;
use yii\base\DynamicModel;

/**
 * Base class for craft-ai tools. Subclasses implement __invoke with strongly-typed
 * parameters. Reflection (plus optional #[\markhuot\craftai\attributes\Tool] and
 * #[\markhuot\craftai\attributes\Description] overrides) translates the signature
 * into JSON Schema for both the in-app agent loop and the MCP server.
 *
 * Tools may return any value; ToolRegistry coerces it into a ToolOutput. Throw
 * an exception (or return a ToolOutput with isError=true) to signal a failure.
 *
 * Parameter validation is declared with #[Validate(...)] attributes and runs
 * automatically before __invoke. Override validate() for runtime/cross-field
 * checks; call parent::validate() first to keep the attribute-driven rules.
 */
abstract class Tool
{
    // Subclasses MUST implement: public function __invoke(...): mixed

    /**
     * Validate the named arguments before __invoke is called. Returns a
     * ToolOutput with isError=true to abort execution, or null to proceed.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function validate(array $arguments): ?ToolOutput
    {
        $reflection = new ReflectionMethod($this, '__invoke');

        /** @var array<string, mixed> $values */
        $values = [];
        /** @var list<array<int, mixed>> $rules */
        $rules = [];

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $values[$name] = $arguments[$name] ?? null;

            foreach ($param->getAttributes(Validate::class) as $attr) {
                /** @var Validate $validate */
                $validate = $attr->newInstance();
                $rules[] = array_merge([[$name], $validate->rule], $validate->options);
            }
        }

        if ($rules === []) {
            return null;
        }

        $model = DynamicModel::validateData($values, $rules);

        if (! $model->hasErrors()) {
            return null;
        }

        $messages = [];
        foreach ($model->getErrors() as $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = $error;
            }
        }

        return new ToolOutput(
            'Validation failed: '.implode('; ', $messages),
            isError: true,
        );
    }
}
