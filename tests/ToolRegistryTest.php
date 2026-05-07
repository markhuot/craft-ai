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
