<?php

namespace markhuot\craftai\tools;

use craft\helpers\UrlHelper;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\records\ArtifactRecord;

/**
 * Persist an agent-authored HTML document (e.g. a rendered revision diff) so it
 * survives reloads, is addressable by a single id across a multi-node deploy,
 * and can be served standalone / downloaded as `diff.html`.
 *
 * This is the *persist* half of the artifact system; {@see OpenArtifact} is the
 * *display* half. The agent calls `render_artifact` to store the HTML, gets
 * back an `artifactId`, then calls `open_artifact` with that id to surface it
 * in the CP preview pane. Keeping save and show as two tools lets the agent
 * re-open an artifact later without regenerating it, and keeps each tool's
 * contract narrow.
 *
 * The HTML must be a complete, self-contained document with inline styles only
 * — no `<script>` and no external resources — because {@see ArtifactsController}
 * serves it under a strict CSP (and the preview pane frames it sandboxed) that
 * blocks both.
 */
class RenderArtifact extends Tool
{
    public const KIND = ToolKind::Read;

    public const ALLOWED_CLIENTS = [ClientType::CP];

    public function __construct(
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    /**
     * @return array{_notes: string, data: array{artifactId: int, url: string}}|ToolOutput
     */
    public function __invoke(
        #[Description('Short title shown above the rendered artifact (e.g. "Revision 7 → Current").')]
        string $title,
        #[Description('A complete, self-contained HTML document. Inline styles only — no <script> tags, no external resources (served under a strict CSP inside a sandboxed iframe).')]
        string $html,
    ): array|ToolOutput {
        if (trim($html) === '') {
            return new ToolOutput('Validation failed: html must not be empty.', isError: true);
        }

        if ($this->context->getClient() !== ClientType::CP) {
            return new ToolOutput(
                'render_artifact is only available in the CP chat surface — the calling client has no preview pane to display the artifact in.',
                isError: true,
            );
        }
        $sessionId = $this->context->requireSessionId();

        $artifact = new ArtifactRecord();
        $artifact->sessionId = $sessionId;
        $artifact->title = $title;
        $artifact->html = $html;
        $artifact->mimeType = 'text/html';
        $artifact->save(false);
        $artifactId = (int) $artifact->id;

        $url = UrlHelper::cpUrl('ai/artifacts/'.$artifactId);

        return [
            '_notes' => "Artifact #{$artifactId} (\"{$title}\") saved at {$url}. Call open_artifact with artifactId {$artifactId} to display it in the preview pane (append ?download to the URL to download it as diff.html).",
            'data' => [
                'artifactId' => $artifactId,
                'url' => $url,
            ],
        ];
    }
}
