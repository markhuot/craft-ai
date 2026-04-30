<?php

namespace markhuot\craftai\tools;

use ReflectionMethod;

class ToolRegistry
{
    /** @var array<string, class-string> */
    private array $tools = [];

    /**
     * @param class-string $toolClass
     */
    public function register(string $toolClass): void
    {
        $schema = new ToolSchema($toolClass);
        $this->tools[$schema->name] = $toolClass;
    }

    /**
     * @return list<ToolSchema>
     */
    public function schemas(): array
    {
        return array_values(array_map(
            static fn (string $class): ToolSchema => new ToolSchema($class),
            $this->tools,
        ));
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
        $tool = new $toolClass();
        $method = new ReflectionMethod($tool, '__invoke');

        $ordered = [];
        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            if (array_key_exists($paramName, $arguments)) {
                $ordered[] = $arguments[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $ordered[] = $param->getDefaultValue();
            }
        }

        $result = $method->invokeArgs($tool, $ordered);
        assert($result instanceof ToolOutput);

        return $result;
    }
}
