<?php

use craft\helpers\StringHelper;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\tools\RenderArtifact;

/**
 * render_artifact is now pure persistence — it writes the ArtifactRecord and
 * hands back the id/URL, leaving display to open_artifact. These tests pin that
 * contract: no preview request is brokered here.
 */
function artifactSession(?int $userId = null): string
{
    $session = new SessionRecord();
    $session->id = StringHelper::UUID();
    $session->active = false;
    $session->userId = $userId;
    $session->save(false);

    return (string) $session->id;
}

it('persists an artifact and returns its id and url', function () {
    $sessionId = artifactSession();

    $context = new ToolContext();
    $context->begin($sessionId, 'tu-1', ClientType::CP);
    $tool = new RenderArtifact($context);

    $output = $tool('Revision 7 → Current', '<!doctype html><html><body>the diff</body></html>');

    expect($output)->toBeArray();
    expect($output['data']['artifactId'])->toBeInt();
    expect($output['data']['url'])->toContain('ai/artifacts/'.$output['data']['artifactId']);
    // The agent is told to follow up with open_artifact to actually show it.
    expect($output['_notes'])->toContain('open_artifact');

    $artifact = ArtifactRecord::findOne(['id' => $output['data']['artifactId']]);
    expect($artifact)->not->toBeNull();
    expect($artifact->sessionId)->toBe($sessionId);
    expect($artifact->title)->toBe('Revision 7 → Current');
    expect($artifact->html)->toContain('the diff');

    // Persisting must not broker any preview request — that's open_artifact's job.
    expect(PreviewRequestRecord::find()->where(['sessionId' => $sessionId])->exists())->toBeFalse();
});

it('returns an error when the html is empty', function () {
    $context = new ToolContext();
    $context->begin(artifactSession(), 'tu', ClientType::CP);
    $tool = new RenderArtifact($context);

    $output = $tool('Title', '   ');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Validation failed');
});

it('errors when invoked from a non-CP surface', function () {
    $tool = new RenderArtifact(new ToolContext());

    $output = $tool('Title', '<html></html>');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('CP chat surface');
});
