<?php

use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\tools\OpenPreview;
use markhuot\craftai\tools\ToolOutput;

/**
 * Stub that records what `create()` was called with and returns whatever
 * record we hand it on the next `waitFor()`. Lets us drive the tool's
 * synchronous flow without actually polling the database.
 */
class FakeOpenPreviewService extends PreviewService
{
    public ?int $createdId = null;

    /** @var array<string, mixed> */
    public array $lastInput = [];

    public ?string $lastSession = null;

    public ?string $lastToolUseId = null;

    public ?string $lastType = null;

    public ?PreviewRequestRecord $next = null;

    public function create(string $sessionId, ?string $toolUseId, string $type, array $input): int
    {
        $this->lastSession = $sessionId;
        $this->lastToolUseId = $toolUseId;
        $this->lastType = $type;
        $this->lastInput = $input;
        $this->createdId = 999;

        return 999;
    }

    public function waitFor(int $id, int $timeoutSeconds, ?callable $shouldAbort = null): PreviewRequestRecord
    {
        if ($this->next === null) {
            throw new \LogicException('Configure FakeOpenPreviewService::$next before calling the tool.');
        }

        return $this->next;
    }
}

function fakeRecord(string $status, ?string $resultJson = null): PreviewRequestRecord
{
    $record = new PreviewRequestRecord();
    $record->status = $status;
    $record->result = $resultJson;

    return $record;
}

it('returns success when the front-end completes the request', function () {
    $service = new FakeOpenPreviewService();
    $service->next = fakeRecord(
        PreviewRequestRecord::STATUS_COMPLETED,
        json_encode(['loadedAt' => 1, 'finalUrl' => 'https://example.com/final']),
    );

    $context = new ToolContext();
    $context->begin('session-open-1', 'tu-1');
    $tool = new OpenPreview($service, $context);

    $output = $tool('https://example.com');

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->isError)->toBeFalse();
    expect($output->text)->toContain('https://example.com/final');
    expect($service->lastSession)->toBe('session-open-1');
    expect($service->lastToolUseId)->toBe('tu-1');
    expect($service->lastType)->toBe('open');
    expect($service->lastInput)->toBe(['url' => 'https://example.com']);
});

it('returns an error output when the front-end fails the request', function () {
    $service = new FakeOpenPreviewService();
    $service->next = fakeRecord(
        PreviewRequestRecord::STATUS_ERRORED,
        json_encode(['error' => 'Iframe load failed']),
    );

    $context = new ToolContext();
    $context->begin('session-open-2', 'tu-2');
    $tool = new OpenPreview($service, $context);

    $output = $tool('https://example.com');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toBe('Iframe load failed');
});

it('rejects URLs that are not http(s) or root-relative', function () {
    $service = new FakeOpenPreviewService();
    $context = new ToolContext();
    $context->begin('session-open-3', 'tu-3');
    $tool = new OpenPreview($service, $context);

    $output = $tool('javascript:alert(1)');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Validation failed');
    // Should not have created a row when validation rejects up front.
    expect($service->createdId)->toBeNull();
});

it('accepts CP-relative paths starting with "/"', function () {
    $service = new FakeOpenPreviewService();
    $service->next = fakeRecord(
        PreviewRequestRecord::STATUS_COMPLETED,
        json_encode(['loadedAt' => 1, 'finalUrl' => '/admin/entries/blog/42']),
    );

    $context = new ToolContext();
    $context->begin('session-open-4', 'tu-4');
    $tool = new OpenPreview($service, $context);

    $output = $tool('/admin/entries/blog/42');

    expect($output->isError)->toBeFalse();
    expect($service->lastInput['url'])->toBe('/admin/entries/blog/42');
});

it('errors when invoked without a session context (e.g., MCP/console)', function () {
    $service = new FakeOpenPreviewService();
    $tool = new OpenPreview($service, new ToolContext());

    $output = $tool('https://example.com');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('active chat session');
    expect($service->createdId)->toBeNull();
});
