<?php

namespace markhuot\craftai\tools;

use Craft;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\binders\Binder;
use markhuot\craftai\permissions\ToolPermissionDeniedException;
use markhuot\craftai\permissions\ToolPermissions;
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
    public function descriptors(bool $includeCpOnly = true, bool $onlyAllowed = false): array
    {
        $names = array_keys($this->tools);
        if (! $includeCpOnly) {
            $names = array_filter($names, fn (string $n): bool => ! ($this->cpOnly[$n] ?? false));
        }
        if ($onlyAllowed) {
            $names = array_filter($names, fn (string $n): bool => $this->isAllowed($n));
        }

        return array_values(array_map(
            fn (string $name): ToolDescriptor => new ToolDescriptor($this->tools[$name]),
            $names,
        ));
    }

    public function isAllowed(string $name): bool
    {
        try {
            $this->assertPermission($name);

            return true;
        } catch (ToolPermissionDeniedException) {
            return false;
        }
    }

    /**
     * Filter a descriptor list to the tools whose declared
     * `ALLOWED_CLIENTS` either includes the supplied client or is empty
     * (meaning unrestricted). Used by the agent loop and the MCP server
     * factory to keep surface-specific tools (e.g. `update_code_component`)
     * off of surfaces that shouldn't see them.
     *
     * Passing `null` is treated as "unknown surface" and matches only
     * tools with no restriction — same conservative default GetPreview
     * et al. apply at runtime.
     *
     * @param  list<ToolDescriptor>  $descriptors
     * @return list<ToolDescriptor>
     */
    public function filterByClient(array $descriptors, ?ClientType $client): array
    {
        return array_values(array_filter(
            $descriptors,
            static function (ToolDescriptor $d) use ($client): bool {
                if ($d->allowedClients === []) {
                    return true;
                }
                if ($client === null) {
                    return false;
                }

                return in_array($client, $d->allowedClients, true);
            },
        ));
    }

    /**
     * Filter a descriptor list down to what a session's tool-mode setting
     * exposes. Read-only mode keeps Read tools; Draft mode keeps Read +
     * DraftWrite; Custom mode keeps the explicit allowlist intersected with
     * the input list (so a tool the user previously checked but no longer has
     * permission for is still excluded). Any unrecognized mode falls back to
     * Full (no further filtering) — this matches the column default.
     *
     * @param  list<ToolDescriptor>  $descriptors
     * @return list<ToolDescriptor>
     */
    public function filterByToolMode(array $descriptors, string $mode, ?string $enabledToolsJson = null): array
    {
        if ($mode === 'full' || $mode === '') {
            return $descriptors;
        }

        if ($mode === 'readonly') {
            return array_values(array_filter(
                $descriptors,
                static fn (ToolDescriptor $d): bool => $d->kind === ToolKind::Read,
            ));
        }

        if ($mode === 'draft') {
            return array_values(array_filter(
                $descriptors,
                static fn (ToolDescriptor $d): bool => $d->kind === ToolKind::Read || $d->kind === ToolKind::DraftWrite,
            ));
        }

        if ($mode === 'custom') {
            $enabled = $this->decodeEnabledTools($enabledToolsJson);

            return array_values(array_filter(
                $descriptors,
                static fn (ToolDescriptor $d): bool => in_array($d->name, $enabled, true),
            ));
        }

        return $descriptors;
    }

    /**
     * @return list<string>
     */
    private function decodeEnabledTools(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $names = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) && $entry !== '') {
                $names[] = $entry;
            }
        }

        return $names;
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

        try {
            $this->assertPermission($name);
        } catch (ToolPermissionDeniedException $e) {
            return new ToolOutput($e->getMessage(), isError: true);
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

        return $this->scrub($name, $this->coerce($result));
    }

    /**
     * Throws if the current Craft user lacks permission to use this tool.
     *
     * Admins always pass. Guests/unauthenticated requests are denied; callers
     * that legitimately run without a user (queue jobs, console) must restore
     * the originating user's identity before invoking.
     */
    public function assertPermission(string $name): void
    {
        if (! isset($this->tools[$name])) {
            throw new \RuntimeException("Unknown tool: {$name}");
        }

        $permission = ToolPermissions::name($name);
        $userComponent = Craft::$app->getUser();
        $identity = $userComponent->getIdentity();

        if ($identity !== null && $identity->admin) {
            return;
        }

        if ($identity !== null && $userComponent->checkPermission($permission)) {
            return;
        }

        throw new ToolPermissionDeniedException($name, $permission);
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

        // JSON_INVALID_UTF8_SUBSTITUTE so a bad byte in a nested array doesn't
        // throw before {@see scrub} ever sees it. The substituted output then
        // flows through scrub() like any other text payload.
        return new ToolOutput(json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    /**
     * Final UTF-8 hygiene pass on every tool output. Tools _should_ return
     * valid UTF-8, but a stray byte from any external source (a fetched page,
     * a shell command, a third-party SDK) would otherwise survive all the way
     * to the provider's json_encode and abort the turn. mb_scrub replaces the
     * bad bytes with U+FFFD so the loop keeps running; the warning log captures
     * the tool name and byte count so the silent recovery is still observable.
     *
     * The image-block `data` field is base64 ASCII, so mb_scrub is a no-op
     * there — no risk of corrupting binary content.
     */
    private function scrub(string $toolName, ToolOutput $output): ToolOutput
    {
        $replaced = 0;

        $scrubbedText = self::scrubString($output->text, $replaced);

        $scrubbedBlocks = null;
        if ($output->blocks !== null) {
            $scrubbedBlocks = [];
            foreach ($output->blocks as $block) {
                /** @var array<string, mixed> $scrubbedBlock */
                $scrubbedBlock = self::scrubArray($block, $replaced);
                $scrubbedBlocks[] = $scrubbedBlock;
            }
        }

        if ($replaced > 0) {
            Craft::warning(
                "Tool {$toolName} returned {$replaced} invalid UTF-8 byte(s); replaced with U+FFFD.",
                __METHOD__,
            );
        }

        if ($replaced === 0) {
            return $output;
        }

        return new ToolOutput(
            text: $scrubbedText,
            isError: $output->isError,
            blocks: $scrubbedBlocks,
        );
    }

    private static function scrubString(string $value, int &$replaced): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $scrubbed = mb_scrub($value, 'UTF-8');
        // strlen() is byte-length here, which is what we want for "bad byte
        // count" — substitution can change the byte length so this is a rough
        // signal, not a precise diff. Good enough for an observability log.
        $replaced += max(1, strlen($value) - strlen($scrubbed));

        return $scrubbed;
    }

    /**
     * @param array<array-key, mixed> $block
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $block, int &$replaced): array
    {
        foreach ($block as $key => $value) {
            if (is_string($value)) {
                $block[$key] = self::scrubString($value, $replaced);
            } elseif (is_array($value)) {
                $block[$key] = self::scrubArray($value, $replaced);
            }
        }

        return $block;
    }
}
