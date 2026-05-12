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

    /** @var array<string, mixed> */
    public readonly array $annotations;

    /**
     * Side-effect classification. Read by the session-scoped tool-mode filter
     * (Full / Draft / Read-only) so each mode can include or exclude this tool
     * without the filter knowing the tool's name.
     */
    public readonly ToolKind $kind;

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
        $this->annotations = $attribute === null ? [] : $attribute->annotations;
        $this->kind = $toolClass::KIND;
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

        $docParamTypes = self::extractDocblockParamTypes($method);

        foreach ($method->getParameters() as $param) {
            $schema = self::parameterToJsonSchema($param, $docParamTypes[$param->getName()] ?? null);
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
    private static function parameterToJsonSchema(ReflectionParameter $param, ?string $docParamType = null): ?array
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
                $variant = self::namedTypeToSchema($unionType, $docParamType);
                if ($variant['type'] === 'object' && $unionType->getName() !== 'array') {
                    continue;
                }
                $variants[] = $variant;
            }

            if ($variants === []) {
                return null;
            }

            $schema = count($variants) === 1 ? $variants[0] : ['oneOf' => $variants];
        } elseif ($type instanceof ReflectionNamedType) {
            $schema = self::namedTypeToSchema($type, $docParamType);
            if ($schema['type'] === 'object' && $type->getName() !== 'array') {
                return null;
            }
        } else {
            $schema = ['type' => 'string'];
        }

        $descAttrs = $param->getAttributes(Description::class);
        if ($descAttrs !== []) {
            $schema['description'] = $descAttrs[0]->newInstance()->value;
        }

        if ($param->isDefaultValueAvailable()) {
            $default = $param->getDefaultValue();
            if ($default !== null) {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /**
     * @return array{type: string}
     */
    private static function namedTypeToSchema(ReflectionNamedType $type, ?string $docParamType = null): array
    {
        return match ($type->getName()) {
            'string' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => self::resolveArrayShape($docParamType)],
            default => ['type' => 'object'],
        };
    }

    /**
     * PHP `array` is overloaded — it covers both JSON arrays (numeric keys) and
     * JSON objects (string keys). Strict MCP schema validators reject the wrong
     * shape, so we disambiguate from the `@param` docblock: `array<string, …>`
     * is an object, `list<…>` and `array<int, …>` are arrays. Plain `array`
     * with no shape hint stays a JSON array to preserve historical behavior.
     */
    private static function resolveArrayShape(?string $docParamType): string
    {
        if ($docParamType === null) {
            return 'array';
        }

        $normalized = trim($docParamType);
        // Strip leading nullable marker so we look at the inner shape.
        $normalized = ltrim($normalized, '?');

        foreach (preg_split('/\s*\|\s*/', $normalized) ?: [$normalized] as $variant) {
            $variant = trim($variant);
            if ($variant === '' || $variant === 'null') {
                continue;
            }
            if (preg_match('/^array<\s*string\b/i', $variant) === 1) {
                return 'object';
            }
        }

        return 'array';
    }

    /**
     * Parse the method's docblock into a map of `param-name => raw-type-string`.
     * Returns an empty map when the docblock is absent or malformed — the
     * caller falls back to PHP type info in that case.
     *
     * @return array<string, string>
     */
    private static function extractDocblockParamTypes(\ReflectionMethod $method): array
    {
        $docComment = $method->getDocComment();
        if ($docComment === false) {
            return [];
        }

        try {
            $docBlock = DocBlockFactory::createInstance()->create($docComment);
        } catch (\Throwable) {
            return [];
        }

        $types = [];
        foreach ($docBlock->getTagsByName('param') as $tag) {
            if (! method_exists($tag, 'getVariableName') || ! method_exists($tag, 'getType')) {
                continue;
            }
            $name = $tag->getVariableName();
            if (! is_string($name) || $name === '') {
                continue;
            }
            $type = $tag->getType();
            if ($type === null) {
                continue;
            }
            $types[$name] = (string) $type;
        }

        return $types;
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
     * @return array{name: string, description: string, inputSchema: array{type: string, properties: array<string, array<string, mixed>>|\stdClass, required: list<string>}, annotations?: array<string, mixed>}
     */
    public function toMcpTool(): array
    {
        $inputSchema = $this->inputSchema;
        if ($inputSchema['properties'] === []) {
            $inputSchema['properties'] = new \stdClass();
        }

        $tool = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $inputSchema,
        ];

        if ($this->annotations !== []) {
            $tool['annotations'] = $this->annotations;
        }

        return $tool;
    }
}
