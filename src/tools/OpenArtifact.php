<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\helpers\UrlHelper;
use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\ArtifactRecord;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\records\SessionRecord;

/**
 * Display an already-persisted artifact (see {@see RenderArtifact}) in the CP
 * chat preview pane, and block until the front-end confirms it mounted.
 *
 * This is the display half of the artifact system; `render_artifact` is the
 * persist half. Splitting them keeps each tool single-purpose: the agent first
 * writes the HTML to the database (getting back an `artifactId`), then opens
 * that id whenever it wants the user to see it — including re-opening an
 * artifact authored earlier in the session or on the compare page.
 *
 * CP-only, and deliberately separate from `open_preview`: `open_preview` loads
 * a *trusted URL* on Craft's own origin and can read it back via `get_preview`;
 * `open_artifact` surfaces *untrusted, model-authored HTML* that has no URL of
 * its own and is served — and framed — under a strict, script-free CSP inside a
 * fully sandboxed iframe. The two never share a sandbox, so they stay two tools.
 */
class OpenArtifact extends Tool
{
    public const KIND = ToolKind::Read;

    public const ALLOWED_CLIENTS = [ClientType::CP];

    public function __construct(
        private readonly PreviewService $preview = new PreviewService(),
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    /**
     * @return array{_notes: string, data: array{artifactId: int, url: string}}|ToolOutput
     */
    public function __invoke(
        #[Description('The id of a previously-saved artifact (returned by render_artifact) to display in the preview pane.')]
        int $artifactId,
        #[Description('Maximum seconds to wait for the pane to confirm display before failing. Clamped to [5, 120]. Defaults to 30.')]
        int $timeoutSeconds = 30,
    ): array|ToolOutput {
        if ($this->context->getClient() !== ClientType::CP) {
            return new ToolOutput(
                'open_artifact is only available in the CP chat surface — the calling client has no preview pane.',
                isError: true,
            );
        }
        $sessionId = $this->context->requireSessionId();

        $artifact = $this->loadOwnedArtifact($artifactId, $sessionId);
        if ($artifact === null) {
            return new ToolOutput(
                "No artifact #{$artifactId} found for this session. Call render_artifact first to create one, then open it by the id it returns.",
                isError: true,
            );
        }

        $title = $artifact->title;
        $url = UrlHelper::cpUrl('ai/artifacts/'.$artifactId);

        $requestId = $this->preview->create(
            $sessionId,
            $this->context->getToolUseId(),
            PreviewRequestRecord::TYPE_ARTIFACT,
            ['artifactId' => $artifactId, 'title' => $title, 'url' => $url],
        );

        $resolved = $this->preview->waitFor(
            $requestId,
            $timeoutSeconds,
            shouldAbort: static fn (): bool => self::sessionStopRequested($sessionId),
        );

        if ($resolved->status === PreviewRequestRecord::STATUS_ERRORED) {
            $payload = $this->preview->decodeResult($resolved);
            $message = is_string($payload['error'] ?? null) ? $payload['error'] : 'Artifact failed to render.';

            return new ToolOutput(
                "Artifact #{$artifactId} is saved (viewable at {$url}) but the preview pane did not confirm display: {$message}",
                isError: true,
            );
        }

        return [
            '_notes' => "Artifact #{$artifactId} (\"{$title}\") is now showing in the preview pane (saved at {$url}; append ?download to download it as diff.html).",
            'data' => [
                'artifactId' => $artifactId,
                'url' => $url,
            ],
        ];
    }

    /**
     * Load the artifact only if it belongs to a session owned by the same user
     * as the current session — mirroring {@see ArtifactsController::loadOwnedArtifact()},
     * but keyed off the session's `userId` rather than the web identity since
     * tools run in a queue worker with no logged-in user. The controller
     * re-checks ownership against the web user when the iframe actually fetches
     * the document, so this is the agent-facing guard, not the only one.
     */
    private function loadOwnedArtifact(int $artifactId, string $sessionId): ?ArtifactRecord
    {
        /** @var ?ArtifactRecord $artifact */
        $artifact = ArtifactRecord::findOne(['id' => $artifactId]);
        if ($artifact === null) {
            return null;
        }

        $owningSession = SessionRecord::findOne(['id' => $artifact->sessionId]);
        if ($owningSession === null) {
            return null;
        }

        // A null owner is anonymous/shared (matches the controller) and is
        // viewable by anyone; otherwise the owner must match the current
        // session's user.
        if ($owningSession->userId !== null && $owningSession->userId !== self::currentUserId($sessionId)) {
            return null;
        }

        return $artifact;
    }

    private static function currentUserId(string $sessionId): ?int
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);
        if ($session !== null && $session->userId !== null) {
            return (int) $session->userId;
        }

        // Fall back to the web identity when the session row is thin — keeps
        // the check working in surfaces that haven't stamped userId yet.
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity !== null ? (int) $identity->id : null;
    }

    private static function sessionStopRequested(string $sessionId): bool
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);

        return $session !== null && (bool) $session->stopRequested;
    }
}
