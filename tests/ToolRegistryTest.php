<?php

use Craft;
use craft\elements\User;
use markhuot\craftai\permissions\ToolPermissionDeniedException;
use markhuot\craftai\permissions\ToolPermissions;
use markhuot\craftai\tools\DeleteDrafts;
use markhuot\craftai\tools\GetHealth;
use markhuot\craftai\tools\Tool;
use markhuot\craftai\tools\ToolDescriptor;
use markhuot\craftai\tools\ToolKind;
use markhuot\craftai\tools\ToolOutput;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftai\tools\UpsertDraft;
use markhuot\craftai\tools\UpsertEntry;

/** Echo back the input for testing */
class ToolRegistryEchoFixture extends Tool
{
    public function __invoke(string $message, int $repeat = 1): string
    {
        return str_repeat($message, $repeat);
    }
}

/** Always throws to verify error handling */
class ToolRegistryThrowingFixture extends Tool
{
    public function __invoke(): string
    {
        throw new \RuntimeException('boom');
    }
}

/** Returns a string with a stray invalid UTF-8 byte. */
class ToolRegistryBadBytesFixture extends Tool
{
    public function __invoke(): string
    {
        return "Hello \x80 World";
    }
}

/** Returns a ToolOutput whose structured blocks contain invalid UTF-8 in a nested string. */
class ToolRegistryBadBlocksFixture extends Tool
{
    public function __invoke(): ToolOutput
    {
        return new ToolOutput(
            text: 'fallback',
            blocks: [
                ['type' => 'text', 'text' => "Bad \xC3\x28 byte"],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'iVBORw0KGgo=']],
            ],
        );
    }
}

/** Returns a nested array (not a ToolOutput) with bad bytes — exercises the coerce path. */
class ToolRegistryBadArrayFixture extends Tool
{
    public function __invoke(): array
    {
        return [
            '_notes' => 'ok',
            'data' => ['content' => "before \x80 after"],
        ];
    }
}

/** Field-scoped fixture for the client-filter tests. */
class ToolRegistryFieldOnlyFixture extends Tool
{
    public const ALLOWED_CLIENTS = [\markhuot\craftai\agent\ClientType::CODE_COMPONENT_FIELD];

    public function __invoke(): string
    {
        return 'field';
    }
}

/** MCP-allowed fixture for the client-filter tests. */
class ToolRegistryMcpOrCpFixture extends Tool
{
    public const ALLOWED_CLIENTS = [
        \markhuot\craftai\agent\ClientType::CP,
        \markhuot\craftai\agent\ClientType::MCP,
    ];

    public function __invoke(): string
    {
        return 'cp-or-mcp';
    }
}

it('registers a tool and lists its descriptor', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    $descriptors = $registry->descriptors();

    expect($descriptors)->toHaveCount(1);
    expect($descriptors[0]->name)->toBe('get_health');
});

it('executes a tool by name with named arguments', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryEchoFixture::class);

    $output = $registry->execute('tool_registry_echo_fixture', [
        'message' => 'hi',
        'repeat' => 3,
    ]);

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->text)->toBe('hihihi');
    expect($output->isError)->toBeFalse();
});

it('falls back to default values for omitted optional parameters', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryEchoFixture::class);

    $output = $registry->execute('tool_registry_echo_fixture', [
        'message' => 'once',
    ]);

    expect($output->text)->toBe('once');
});

it('returns an error ToolOutput when the tool is unknown', function () {
    $registry = new ToolRegistry();

    $output = $registry->execute('does_not_exist', []);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Unknown tool');
});

it('omits cp-only tools when descriptors are requested without them', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);
    $registry->register(ToolRegistryEchoFixture::class, cpOnly: true);

    $all = $registry->descriptors();
    $public = $registry->descriptors(includeCpOnly: false);

    expect($all)->toHaveCount(2);
    expect($public)->toHaveCount(1);
    expect($public[0]->name)->toBe('get_health');
});

it('returns an error ToolOutput when no user is logged in', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    Craft::$app->getUser()->setIdentity(null);

    $output = $registry->execute('get_health', []);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('do not have permission');
    expect($output->text)->toContain(ToolPermissions::name('get_health'));
});

it('throws a permission exception from assertPermission for a guest', function () {
    $registry = new ToolRegistry();
    $registry->register(GetHealth::class);

    Craft::$app->getUser()->setIdentity(null);

    expect(fn () => $registry->assertPermission('get_health'))
        ->toThrow(ToolPermissionDeniedException::class);
});

it('catches exceptions thrown by tools and returns an error ToolOutput', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryThrowingFixture::class);

    $output = $registry->execute('tool_registry_throwing_fixture', []);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toBe('boom');
});

it('scrubs invalid UTF-8 from a tool that returns a string with bad bytes', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryBadBytesFixture::class);

    $output = $registry->execute('tool_registry_bad_bytes_fixture', []);

    expect($output->isError)->toBeFalse();
    expect(mb_check_encoding($output->text, 'UTF-8'))->toBeTrue();
    expect(fn () => json_encode($output->text, JSON_THROW_ON_ERROR))->not->toThrow(\JsonException::class);
});

it('scrubs invalid UTF-8 inside nested blocks and preserves base64 image data', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryBadBlocksFixture::class);

    $output = $registry->execute('tool_registry_bad_blocks_fixture', []);

    expect($output->blocks)->not->toBeNull();
    expect(mb_check_encoding($output->blocks[0]['text'], 'UTF-8'))->toBeTrue();
    // Base64 ASCII passes through unchanged — mb_scrub on ASCII is a no-op.
    expect($output->blocks[1]['source']['data'])->toBe('iVBORw0KGgo=');
    expect(fn () => json_encode($output->blocks, JSON_THROW_ON_ERROR))->not->toThrow(\JsonException::class);
});

it('scrubs invalid UTF-8 when a tool returns a plain array (coerced through json_encode)', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryBadArrayFixture::class);

    $output = $registry->execute('tool_registry_bad_array_fixture', []);

    expect($output->isError)->toBeFalse();
    expect(mb_check_encoding($output->text, 'UTF-8'))->toBeTrue();
    // The coerced JSON should still be parseable and contain the placeholder
    // where the bad byte was substituted.
    $decoded = json_decode($output->text, true);
    expect($decoded['data']['content'])->toContain("\u{FFFD}");
});

it('emits a Craft warning that names the tool when scrubbing fires', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryBadBytesFixture::class);

    // Hold the logger buffer so messages don't auto-flush to targets and
    // disappear before we can inspect them. flushInterval=10000 is well above
    // anything a single test will produce.
    $logger = Craft::getLogger();
    $previousInterval = $logger->flushInterval;
    $logger->flushInterval = 10000;
    $logger->messages = [];

    try {
        $registry->execute('tool_registry_bad_bytes_fixture', []);

        $messages = $logger->messages;
        $hasWarning = false;
        foreach ($messages as $entry) {
            // Yii log entries are [text, level, category, timestamp, traces, memory].
            if (is_string($entry[0])
                && str_contains($entry[0], 'tool_registry_bad_bytes_fixture')
                && str_contains($entry[0], 'invalid UTF-8')) {
                $hasWarning = true;
                break;
            }
        }

        expect($hasWarning)->toBeTrue();
    } finally {
        $logger->flushInterval = $previousInterval;
    }
});

it('does not warn when the tool output is already clean UTF-8', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryEchoFixture::class);

    $logger = Craft::getLogger();
    $previousInterval = $logger->flushInterval;
    $logger->flushInterval = 10000;
    $logger->messages = [];

    try {
        $registry->execute('tool_registry_echo_fixture', ['message' => 'hi', 'repeat' => 1]);

        $hadUtfWarning = false;
        foreach ($logger->messages as $entry) {
            if (is_string($entry[0]) && str_contains($entry[0], 'invalid UTF-8')) {
                $hadUtfWarning = true;
                break;
            }
        }

        expect($hadUtfWarning)->toBeFalse();
    } finally {
        $logger->flushInterval = $previousInterval;
    }
});

function descriptorsForToolModeTest(): array
{
    return [
        new ToolDescriptor(GetHealth::class),     // read
        new ToolDescriptor(UpsertDraft::class),   // draftWrite
        new ToolDescriptor(DeleteDrafts::class),  // draftWrite
        new ToolDescriptor(UpsertEntry::class),   // liveWrite (default)
    ];
}

it('keeps every descriptor in full mode', function () {
    $filtered = (new ToolRegistry())->filterByToolMode(descriptorsForToolModeTest(), 'full');

    expect(array_map(fn ($d) => $d->name, $filtered))->toBe([
        'get_health',
        'upsert_draft',
        'delete_drafts',
        'upsert_entry',
    ]);
});

it('keeps only Read descriptors in readonly mode', function () {
    $filtered = (new ToolRegistry())->filterByToolMode(descriptorsForToolModeTest(), 'readonly');

    expect(array_map(fn ($d) => $d->name, $filtered))->toBe(['get_health']);
});

it('keeps Read and DraftWrite descriptors in draft mode', function () {
    $filtered = (new ToolRegistry())->filterByToolMode(descriptorsForToolModeTest(), 'draft');

    expect(array_map(fn ($d) => $d->name, $filtered))->toBe([
        'get_health',
        'upsert_draft',
        'delete_drafts',
    ]);
});

it('keeps only the explicitly enabled descriptors in custom mode', function () {
    $filtered = (new ToolRegistry())->filterByToolMode(
        descriptorsForToolModeTest(),
        'custom',
        json_encode(['get_health', 'upsert_entry']),
    );

    expect(array_map(fn ($d) => $d->name, $filtered))->toBe(['get_health', 'upsert_entry']);
});

it('treats a missing custom allowlist as an empty selection', function () {
    $filtered = (new ToolRegistry())->filterByToolMode(
        descriptorsForToolModeTest(),
        'custom',
        null,
    );

    expect($filtered)->toBe([]);
});

it('falls back to full mode for an unrecognized mode value', function () {
    $filtered = (new ToolRegistry())->filterByToolMode(descriptorsForToolModeTest(), 'totally-bogus');

    expect($filtered)->toHaveCount(4);
});

it('ignores garbage JSON in the custom allowlist without throwing', function () {
    $filtered = (new ToolRegistry())->filterByToolMode(
        descriptorsForToolModeTest(),
        'custom',
        '{not valid json',
    );

    expect($filtered)->toBe([]);
});

it('filterByClient keeps unrestricted tools and drops tools the client is not in', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryEchoFixture::class);            // ALLOWED_CLIENTS = []
    $registry->register(ToolRegistryFieldOnlyFixture::class);       // [CODE_COMPONENT_FIELD]
    $registry->register(ToolRegistryMcpOrCpFixture::class);         // [CP, MCP]

    $all = $registry->descriptors();
    expect($all)->toHaveCount(3);

    $forField = $registry->filterByClient($all, \markhuot\craftai\agent\ClientType::CODE_COMPONENT_FIELD);
    $forFieldNames = array_map(static fn ($d) => $d->name, $forField);
    expect($forFieldNames)->toContain('tool_registry_echo_fixture');
    expect($forFieldNames)->toContain('tool_registry_field_only_fixture');
    // The CP/MCP-only tool is not in the field surface.
    expect($forFieldNames)->not->toContain('tool_registry_mcp_or_cp_fixture');

    $forMcp = $registry->filterByClient($all, \markhuot\craftai\agent\ClientType::MCP);
    $forMcpNames = array_map(static fn ($d) => $d->name, $forMcp);
    expect($forMcpNames)->toContain('tool_registry_echo_fixture');
    expect($forMcpNames)->toContain('tool_registry_mcp_or_cp_fixture');
    // The field-only tool is hidden from MCP.
    expect($forMcpNames)->not->toContain('tool_registry_field_only_fixture');
});

it('filterByClient drops restricted tools when the client is null', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryEchoFixture::class);
    $registry->register(ToolRegistryFieldOnlyFixture::class);

    $filtered = $registry->filterByClient($registry->descriptors(), null);
    $names = array_map(static fn ($d) => $d->name, $filtered);
    expect($names)->toContain('tool_registry_echo_fixture');
    expect($names)->not->toContain('tool_registry_field_only_fixture');
});

it('exposes the tool\'s ALLOWED_CLIENTS list on the descriptor', function () {
    $registry = new ToolRegistry();
    $registry->register(ToolRegistryFieldOnlyFixture::class);

    $descriptor = $registry->describe('tool_registry_field_only_fixture');
    expect($descriptor)->not->toBeNull();
    expect($descriptor->allowedClients)->toBe([\markhuot\craftai\agent\ClientType::CODE_COMPONENT_FIELD]);
});
