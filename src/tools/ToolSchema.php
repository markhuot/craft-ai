<?php

namespace markhuot\craftai\tools;

use markhuot\craftai\attributes\Description;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class ToolSchema
{
    public readonly string $name;

    public readonly string $description;

    /** @var array{type: string, properties: array<string, array<string, mixed>>, required: list<string>} */
    public readonly array $inputSchema;

    /**
     * @param class-string $toolClass
     */
    public function __construct(
        public readonly string $toolClass,
    ) {
        $reflection = new ReflectionClass($toolClass);
        $this->name = self::deriveName($reflection);
        $this->description = self::extractDescription($reflection);
        $this->inputSchema = self::buildInputSchema($reflection);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function deriveName(ReflectionClass $reflection): string
    {
        $shortName = $reflection->getShortName();

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function extractDescription(ReflectionClass $reflection): string
    {
        $attributes = $reflection->getAttributes(Description::class);

        if ($attributes === []) {
            return '';
        }

        return $attributes[0]->newInstance()->value;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}
     */
    private static function buildInputSchema(ReflectionClass $reflection): array
    {
        $method = $reflection->getMethod('__invoke');
        /** @var array<string, array<string, mixed>> $properties */
        $properties = [];
        /** @var list<string> $required */
        $required = [];

        foreach ($method->getParameters() as $param) {
            $properties[$param->getName()] = self::parameterToJsonSchema($param);

            if (! $param->isOptional() && ! $param->allowsNull()) {
                $required[] = $param->getName();
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parameterToJsonSchema(ReflectionParameter $param): array
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType) {
            $schema = self::namedTypeToSchema($type);
        } elseif ($type instanceof ReflectionUnionType) {
            $variants = [];
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType) {
                    $variants[] = self::namedTypeToSchema($unionType);
                }
            }
            $schema = ['oneOf' => $variants];
        } else {
            $schema = ['type' => 'string'];
        }

        $descAttrs = $param->getAttributes(Description::class);
        if ($descAttrs !== []) {
            $schema['description'] = $descAttrs[0]->newInstance()->value;
        }

        return $schema;
    }

    /**
     * @return array{type: string}
     */
    private static function namedTypeToSchema(ReflectionNamedType $type): array
    {
        return match ($type->getName()) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array'],
            default => ['type' => 'object'],
        };
    }

    /**
     * @return array{name: string, description: string, input_schema: array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}}
     */
    public function toAnthropicTool(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }

    /**
     * @return array{name: string, description: string, inputSchema: array{type: string, properties: array<string, array<string, mixed>>, required: list<string>}}
     */
    public function toMcpTool(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }
}
