<?php

use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\tools\GetPreview;

class FakeGetPreviewService extends PreviewService
{
    public ?int $createdId = null;

    /** @var array<string, mixed> */
    public array $lastInput = [];

    public ?string $lastType = null;

    public ?PreviewRequestRecord $next = null;

    public function create(string $sessionId, ?string $toolUseId, string $type, array $input): int
    {
        $this->lastInput = $input;
        $this->lastType = $type;
        $this->createdId = 1234;

        return 1234;
    }

    public function waitFor(int $id, int $timeoutSeconds, ?callable $shouldAbort = null): PreviewRequestRecord
    {
        if ($this->next === null) {
            throw new \LogicException('Configure FakeGetPreviewService::$next before calling the tool.');
        }

        return $this->next;
    }
}

function getPreviewRecord(string $status, ?array $payload = null): PreviewRequestRecord
{
    $record = new PreviewRequestRecord();
    $record->status = $status;
    $record->result = $payload === null ? null : json_encode($payload);

    return $record;
}

it('returns the iframe content the front-end produced', function () {
    $service = new FakeGetPreviewService();
    $service->next = getPreviewRecord(PreviewRequestRecord::STATUS_COMPLETED, [
        'content' => 'Hello from the page',
        'mode' => 'text',
    ]);

    $context = new ToolContext();
    $context->begin('session-get-1', 'tu-g-1', ClientType::CP);
    $tool = new GetPreview($service, $context);

    $output = $tool();

    expect($output->isError)->toBeFalse();
    expect($output->text)->toBe('Hello from the page');
    expect($service->lastType)->toBe('get');
    expect($service->lastInput)->toBe(['fullHtml' => false]);
});

it('forwards the fullHtml flag when requested', function () {
    $service = new FakeGetPreviewService();
    $service->next = getPreviewRecord(PreviewRequestRecord::STATUS_COMPLETED, [
        'content' => '<html><body>x</body></html>',
        'mode' => 'full',
    ]);

    $context = new ToolContext();
    $context->begin('session-get-2', 'tu-g-2', ClientType::CP);
    $tool = new GetPreview($service, $context);

    $output = $tool(fullHtml: true);

    expect($output->text)->toContain('<html>');
    expect($service->lastInput)->toBe(['fullHtml' => true]);
});

it('returns the front-end error verbatim when the read fails', function () {
    $service = new FakeGetPreviewService();
    $service->next = getPreviewRecord(PreviewRequestRecord::STATUS_ERRORED, [
        'error' => 'Cross-origin preview: cannot read iframe contents.',
    ]);

    $context = new ToolContext();
    $context->begin('session-get-3', 'tu-g-3', ClientType::CP);
    $tool = new GetPreview($service, $context);

    $output = $tool();

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Cross-origin');
});

it('errors when invoked from a non-CP surface', function () {
    $service = new FakeGetPreviewService();
    $tool = new GetPreview($service, new ToolContext());

    $output = $tool();

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('CP chat surface');
});

it('errors when invoked from MCP', function () {
    $service = new FakeGetPreviewService();
    $context = new ToolContext();
    $context->begin(null, null, ClientType::MCP);
    $tool = new GetPreview($service, $context);

    $output = $tool();

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('CP chat surface');
});

it('errors when invoked from the front-end widget', function () {
    $service = new FakeGetPreviewService();
    $context = new ToolContext();
    $context->begin('session-widget-get', 'tu-w-g', ClientType::WIDGET);
    $tool = new GetPreview($service, $context);

    $output = $tool();

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('CP chat surface');
});

it('truncates oversized content with a clear marker so the LLM context stays bounded', function () {
    // Mark's bug: a CP edit page returned 205KB of HTML, which both
    // burned tokens and (before the schema fix) overflowed the messages
    // table column. Even with the wider column, we cap aggressively so
    // future huge pages don't fill the LLM context.
    $oversized = str_repeat('x', GetPreview::MAX_OUTPUT_BYTES + 50_000);
    $service = new FakeGetPreviewService();
    $service->next = getPreviewRecord(PreviewRequestRecord::STATUS_COMPLETED, [
        'content' => $oversized,
        'mode' => 'full',
    ]);

    $context = new ToolContext();
    $context->begin('session-get-truncate', 'tu-g-truncate', ClientType::CP);
    $tool = new GetPreview($service, $context);

    $output = $tool(fullHtml: true);

    expect($output->isError)->toBeFalse();
    expect(strlen($output->text))->toBeLessThanOrEqual(GetPreview::MAX_OUTPUT_BYTES + 500);
    expect($output->text)->toContain('[Truncated:');
});
