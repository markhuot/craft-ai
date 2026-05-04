<?php

namespace markhuot\craftai\tools;

use Craft;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\binders\Binder;
use ReflectionMethod;
use Throwable;

class ToolRegistry
{
    /** @var array<string, class-string<Tool>> */
    private array $tools = [];

    /** @var array<string, bool> */
    private array $cpOnly = [];

    /**
     * @param class-string<Tool> $toolClass
     */
    public function register(string $toolClass, bool $cpOnly = false): void
    {
        $descriptor = new ToolDescriptor($toolClass);
        $this->tools[$descriptor->name] = $toolClass;
        $this->cpOnly[$descriptor->name] = $cpOnly;
    }

    /**
     * @return list<ToolDescriptor>
     */
    public function descriptors(bool $includeCpOnly = true): array
    {
        $names = array_keys($this->tools);
        if (! $includeCpOnly) {
            $names = array_filter($names, fn (string $n): bool => ! ($this->cpOnly[$n] ?? false));
        }

        return array_values(array_map(
            fn (string $name): ToolDescriptor => new ToolDescriptor($this->tools[$name]),
            $names,
        ));
    }

    public function describe(string $name): ?ToolDescriptor
    {
        if (! isset($this->tools[$name])) {
            return null;
        }

        return new ToolDescriptor($this->tools[$name]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(string $name, array $arguments): ToolOutput
    {
        if (! isset($this->tools[$name])) {
            return new ToolOutput("Unknown tool: {$name}", isError: true);
        }

        $toolClass = $this->tools[$name];

        try {
            $tool = $this->instantiate($toolClass);

            if (($error = $tool->validate($arguments, Tool::PHASE_UNBOUND)) !== null) {
                return $error;
            }

            $method = new ReflectionMethod($tool, '__invoke');

            $bound = $arguments;
            foreach ($method->getParameters() as $param) {
                $bindAttrs = $param->getAttributes(Bind::class);
                if ($bindAttrs === []) {
                    continue;
                }

                /** @var Bind $bind */
                $bind = $bindAttrs[0]->newInstance();
                /** @var Binder $binder */
                $binder = new ($bind->binder)(...$bind->options);
                $bound[$param->getName()] = $binder->bind(
                    $arguments[$param->getName()] ?? null,
                    $arguments,
                );
            }

            if (($error = $tool->validate($bound, Tool::PHASE_BOUND)) !== null) {
                return $error;
            }

            $ordered = [];
            foreach ($method->getParameters() as $param) {
                $paramName = $param->getName();
                if (array_key_exists($paramName, $bound)) {
                    $ordered[] = $bound[$paramName];
                } elseif ($param->isDefaultValueAvailable()) {
                    $ordered[] = $param->getDefaultValue();
                } elseif ($param->allowsNull()) {
                    $ordered[] = null;
                }
            }

            $result = $method->invokeArgs($tool, $ordered);
        } catch (Throwable $e) {
            return new ToolOutput($e->getMessage(), isError: true);
        }

        return $this->coerce($result);
    }

    /**
     * @param class-string<Tool> $toolClass
     */
    private function instantiate(string $toolClass): Tool
    {
        /** @var Tool $instance */
        $instance = Craft::$container->get($toolClass);

        return $instance;
    }

    private function coerce(mixed $result): ToolOutput
    {
        if ($result instanceof ToolOutput) {
            return $result;
        }

        if (is_string($result)) {
            return new ToolOutput($result);
        }

        if (is_scalar($result)) {
            return new ToolOutput((string) $result);
        }

        if ($result === null) {
            return new ToolOutput('');
        }

        return new ToolOutput(json_encode($result, JSON_THROW_ON_ERROR));
    }
}
