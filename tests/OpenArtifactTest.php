<?php

use craft\helpers\StringHelper;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\OpenArtifact;

/**
 * Stubs the broker so the tool's flow runs without polling the database.
 */
class FakeOpenArtifactPreviewService extends PreviewService
{
    /** @var array<string, mixed> */
    public array $lastInput = [];

    public ?string $lastType = null;

    public ?int $createdId = null;

    public ?PreviewRequestRecord $next = null;

    public function create(string $sessionId, ?string $toolUseId, string $type, array $input): int
    {
        $this->lastType = $type;
        $this->lastInput = $input;
        $this->createdId = 555;

        return 555;
    }

    public function waitFor(int $id, int $timeoutSeconds, ?callable $shouldAbort = null): PreviewRequestRecord
    {
        if ($this->next === null) {
            throw new \LogicException('Configure FakeOpenArtifactPreviewService::$next before calling the tool.');
        }

        return $this->next;
    }
}

function openArtifactRecord(string $status, ?string $resultJson = null): PreviewRequestRecord
{
    $record = new PreviewRequestRecord();
    $record->status = $status;
    $record->result = $resultJson;

    return $record;
}

function openArtifactSession(?int $userId = null): string
{
    $session = new SessionRecord();
    $session->id = StringHelper::UUID();
    $session->active = false;
    $session->userId = $userId;
    $session->save(false);

    return (string) $session->id;
}

function makeStoredArtifact(string $sessionId, string $title = 'Revision 7 → Current'): int
{
    $artifact = new ArtifactRecord();
    $artifact->sessionId = $sessionId;
    $artifact->title = $title;
    $artifact->html = '<!doctype html><html><body>the diff</body></html>';
    $artifact->mimeType = 'text/html';
    $artifact->save(false);

    return (int) $artifact->id;
}

it('opens a saved artifact and confirms display', function () {
    $sessionId = openArtifactSession(1);
    $artifactId = makeStoredArtifact($sessionId);

    $service = new FakeOpenArtifactPreviewService();
    $service->next = openArtifactRecord(PreviewRequestRecord::STATUS_COMPLETED, json_encode(['ok' => true]));

    $context = new ToolContext();
    $context->begin($sessionId, 'tu-1', ClientType::CP);
    $tool = new OpenArtifact($service, $context);

    $output = $tool($artifactId);

    expect($output)->toBeArray();
    expect($output['data']['artifactId'])->toBe($artifactId);
    expect($output['data']['url'])->toContain('ai/artifacts/'.$artifactId);
    expect($output['_notes'])->toContain('preview pane');

    // It brokers an artifact-type request carrying the id, title and url.
    expect($service->lastType)->toBe('artifact');
    expect($service->lastInput['artifactId'])->toBe($artifactId);
    expect($service->lastInput['title'])->toBe('Revision 7 → Current');
    expect($service->lastInput['url'])->toContain('ai/artifacts/'.$artifactId);
});

it('errors when the artifact id does not exist', function () {
    $sessionId = openArtifactSession(1);

    $service = new FakeOpenArtifactPreviewService();
    $context = new ToolContext();
    $context->begin($sessionId, 'tu', ClientType::CP);
    $tool = new OpenArtifact($service, $context);

    $output = $tool(987654);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('No artifact #987654');
    // No request brokered when the artifact can't be found.
    expect($service->createdId)->toBeNull();
});

it('refuses to open an artifact owned by another user', function () {
    $otherSession = openArtifactSession(createOtherUser('open-artifact'));
    $artifactId = makeStoredArtifact($otherSession);

    $mySession = openArtifactSession(1);
    $service = new FakeOpenArtifactPreviewService();
    $context = new ToolContext();
    $context->begin($mySession, 'tu', ClientType::CP);
    $tool = new OpenArtifact($service, $context);

    $output = $tool($artifactId);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('No artifact');
    expect($service->createdId)->toBeNull();
});

it('surfaces a pane failure as an error but notes the artifact is saved', function () {
    $sessionId = openArtifactSession(1);
    $artifactId = makeStoredArtifact($sessionId);

    $service = new FakeOpenArtifactPreviewService();
    $service->next = openArtifactRecord(PreviewRequestRecord::STATUS_ERRORED, json_encode(['error' => 'pane closed']));

    $context = new ToolContext();
    $context->begin($sessionId, 'tu', ClientType::CP);
    $tool = new OpenArtifact($service, $context);

    $output = $tool($artifactId);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('saved');
    expect($output->text)->toContain('pane closed');
});

it('errors when invoked from a non-CP surface', function () {
    $service = new FakeOpenArtifactPreviewService();
    $context = new ToolContext();
    $context->begin('session-mcp', 'tu', ClientType::MCP);
    $tool = new OpenArtifact($service, $context);

    $output = $tool(1);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('CP chat surface');
    expect($service->createdId)->toBeNull();
});
