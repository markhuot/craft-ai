<?php

namespace markhuot\craftai\tools;

use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Single source of truth for a tool's metadata. Built once via reflection and
 * adapted to the shape each consumer (Anthropic, OpenAI, MCP) expects.
 */
class ToolDescriptor
{
    public readonly string $name;

    public readonly string $description;

    /** @var array{type: string, properties: array<string, array<string, mixed>>, required: list<string>} */
    public readonly array $inputSchema;

    /**
     * @param class-string<Tool> $toolClass
     */
    public function __construct(
        public readonly string $toolClass,
    ) {
        $reflection = new ReflectionClass($toolClass);
        $attribute = self::readToolAttribute($reflection);

        $this->name = $attribute === null
            ? self::deriveName($reflection)
            : ($attribute->name ?? self::deriveName($reflection));
        $this->description = $attribute === null
            ? self::extractDescription($reflection)
            : ($attribute->description ?? self::extractDescription($reflection));
        $this->inputSchema = self::buildInputSchema($reflection);
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
     */
    private static function readToolAttribute(ReflectionClass $reflection): ?ToolAttribute
    {
        $attrs = $reflection->getAttributes(ToolAttribute::class);

        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
     */
    private static function deriveName(ReflectionClass $reflection): string
    {
        $shortName = $reflection->getShortName();

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
     */
    private static function extractDescription(ReflectionClass $reflection): string
    {
        $docComment = $reflection->getDocComment();
        if ($docComment === false) {
            return '';
        }

        try {
            $factory = DocBlockFactory::createInstance();
            $docBlock = $factory->create($docComment);
            $summary = trim($docBlock->getSummary());
            $description = trim((string) $docBlock->getDescription());

            return $description !== '' ? $summary."\n\n".$description : $summary;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
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
            $schema = self::parameterToJsonSchema($param);
            if ($schema === null) {
                continue;
            }

            $properties[$param->getName()] = $schema;

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
     * @return array<string, mixed>|null
     */
    private static function parameterToJsonSchema(ReflectionParameter $param): ?array
    {
        $type = $param->getType();

        if ($type instanceof ReflectionUnionType) {
            $variants = [];
            foreach ($type->getTypes() as $unionType) {
                if (! $unionType instanceof ReflectionNamedType) {
                    continue;
                }
                if ($unionType->getName() === 'null') {
                    continue;
                }
                $variant = self::namedTypeToSchema($unionType);
                if ($variant['type'] === 'object') {
                    continue;
                }
                $variants[] = $variant;
            }

            if ($variants === []) {
                return null;
            }

            $schema = count($variants) === 1 ? $variants[0] : ['oneOf' => $variants];
        } elseif ($type instanceof ReflectionNamedType) {
            $schema = self::namedTypeToSchema($type);
            if ($schema['type'] === 'object') {
                return null;
            }
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
     * @return array{type: string, function: array{name: string, description: string, parameters: array{type: string, properties: array<string, array<string, mixed>>|\stdClass, required: list<string>}}}
     */
    public function toOpenAiTool(): array
    {
        // Force `properties` to serialize as a JSON object (`{}`) rather than
        // an array (`[]`) when empty. Strict OpenAI-compatible providers
        // (e.g. DeepSeek via opencode.ai) reject `properties: []`.
        $parameters = $this->inputSchema;
        if ($parameters['properties'] === []) {
            $parameters['properties'] = new \stdClass();
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $parameters,
            ],
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
