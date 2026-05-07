<?php

namespace markhuot\craftai\tools;

use markhuot\craftai\attributes\Validate;
use markhuot\craftai\validators\ValidatesBoundParameters;
use markhuot\craftai\validators\ValidatesUnboundParameters;
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
 * Parameter validation is declared with #[Validate(...)] attributes. The
 * registry calls validate() twice — once with $phase='unbound' before binders
 * run, and once with $phase='bound' after. Class-based rules opt into a phase
 * by implementing ValidatesUnboundParameters and/or ValidatesBoundParameters;
 * built-in Yii rule aliases (and class rules implementing neither interface)
 * default to the unbound phase.
 */
abstract class Tool
{
    public const PHASE_UNBOUND = 'unbound';

    public const PHASE_BOUND = 'bound';

    /**
     * What kinds of side effects this tool can produce. Subclasses override to
     * declare a less restrictive kind; the conservative default means a tool
     * that forgets to declare won't slip into Read-only or Draft mode.
     */
    public const KIND = ToolKind::LiveWrite;

    /**
     * Validate the named arguments against the rules that apply to the given
     * phase. Returns a ToolOutput with isError=true to abort, or null to proceed.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function validate(array $arguments, string $phase = self::PHASE_UNBOUND): ?ToolOutput
    {
        $reflection = new ReflectionMethod($this, '__invoke');

        /** @var array<string, mixed> $values */
        $values = [];
        /** @var list<array<int, mixed>> $rules */
        $rules = [];

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $values[$name] = $arguments[$name] ?? null;

            if ($phase === self::PHASE_UNBOUND
                && ! $param->isDefaultValueAvailable()
                && ! $param->allowsNull()
            ) {
                $rules[] = [[$name], 'required'];
            }

            foreach ($param->getAttributes(Validate::class) as $attr) {
                /** @var Validate $validate */
                $validate = $attr->newInstance();
                if (! $this->ruleAppliesToPhase($validate->rule, $phase)) {
                    continue;
                }
                $options = $validate->options;
                $conditions = [];
                if ($validate->whenMissing !== null) {
                    $sibling = $validate->whenMissing;
                    $conditions[] = static fn ($model): bool => self::isEmpty($model->{$sibling} ?? null);
                }
                if ($validate->whenPresent !== null) {
                    $sibling = $validate->whenPresent;
                    $conditions[] = static fn ($model): bool => ! self::isEmpty($model->{$sibling} ?? null);
                }
                if ($validate->whenIsA !== []) {
                    $checks = $validate->whenIsA;
                    $conditions[] = static function ($model) use ($checks): bool {
                        foreach ($checks as $sibling => $class) {
                            $value = $model->{$sibling} ?? null;
                            if (! is_object($value) && ! is_string($value)) {
                                continue;
                            }
                            if (is_a($value, $class, true)) {
                                return true;
                            }
                        }

                        return false;
                    };
                }
                if ($conditions !== []) {
                    $options['when'] = static function ($model) use ($conditions): bool {
                        foreach ($conditions as $cond) {
                            if (! $cond($model)) {
                                return false;
                            }
                        }

                        return true;
                    };
                }
                $rules[] = array_merge([[$name], $validate->rule], $options);
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

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function ruleAppliesToPhase(string $rule, string $phase): bool
    {
        if (! class_exists($rule)) {
            return $phase === self::PHASE_UNBOUND;
        }

        $unbound = is_subclass_of($rule, ValidatesUnboundParameters::class);
        $bound = is_subclass_of($rule, ValidatesBoundParameters::class);

        if (! $unbound && ! $bound) {
            return $phase === self::PHASE_UNBOUND;
        }

        return $phase === self::PHASE_BOUND ? $bound : $unbound;
    }
}
