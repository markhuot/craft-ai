<?php

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
    $context->begin('session-get-1', 'tu-g-1');
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
    $context->begin('session-get-2', 'tu-g-2');
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
    $context->begin('session-get-3', 'tu-g-3');
    $tool = new GetPreview($service, $context);

    $output = $tool();

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Cross-origin');
});

it('errors when invoked without a session context', function () {
    $service = new FakeGetPreviewService();
    $tool = new GetPreview($service, new ToolContext());

    $output = $tool();

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('active chat session');
});
