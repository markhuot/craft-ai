<?php

namespace markhuot\craftai\tools;

use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\records\SessionRecord;

/**
 * Open a URL in the CP-side preview pane and block until the front-end reports
 * the iframe finished loading (or failed/timed out).
 *
 * CP-only: this tool drives the chat widget's preview panel and has no
 * meaning on the front-end widget or over MCP. Pair with `get_preview` to
 * read the iframe contents once it's open.
 *
 * The agent loop persists a request row, the front-end polls for it via the
 * existing `/messages` endpoint, mounts an iframe at `url`, and POSTs back to
 * `/craft-ai/preview/respond` when the iframe's `onload` (or `onerror`) fires.
 * No browser-level success signal exists for HTTP errors — the page still
 * "loads" — so callers should follow up with `get_preview` if they need to
 * verify the rendered content.
 */
class OpenPreview extends Tool
{
    public function __construct(
        private readonly PreviewService $preview = new PreviewService(),
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    /**
     * @return ToolOutput
     */
    public function __invoke(
        #[Description('Absolute URL to load in the preview pane (http:// or https://). May also be a CP-relative path like "/admin/entries/blog/123".')]
        string $url,
        #[Description('Maximum seconds to wait for the iframe to load before failing the tool. Clamped to [5, 120]. Defaults to 30.')]
        int $timeoutSeconds = 30,
    ): ToolOutput {
        if (! self::looksLikeUrl($url)) {
            return new ToolOutput(
                'Validation failed: url must start with http://, https://, or "/" (a CP path).',
                isError: true,
            );
        }

        $sessionId = $this->context->getSessionId();
        if ($sessionId === null) {
            return new ToolOutput(
                'open_preview can only be invoked from inside an active chat session.',
                isError: true,
            );
        }

        $requestId = $this->preview->create(
            $sessionId,
            $this->context->getToolUseId(),
            PreviewRequestRecord::TYPE_OPEN,
            ['url' => $url],
        );

        $resolved = $this->preview->waitFor(
            $requestId,
            $timeoutSeconds,
            shouldAbort: static fn (): bool => self::sessionStopRequested($sessionId),
        );

        if ($resolved->status === PreviewRequestRecord::STATUS_ERRORED) {
            $payload = $this->preview->decodeResult($resolved);
            $message = is_string($payload['error'] ?? null) ? $payload['error'] : 'Preview failed to open.';

            return new ToolOutput($message, isError: true);
        }

        $payload = $this->preview->decodeResult($resolved);
        $finalUrl = is_string($payload['finalUrl'] ?? null) ? $payload['finalUrl'] : $url;

        return new ToolOutput("Preview opened at {$finalUrl}.");
    }

    private static function looksLikeUrl(string $url): bool
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return true;
        }

        return str_starts_with($url, '/');
    }

    /**
     * Cooperative-cancel hook for the wait loop — if the user clicks Stop
     * while the preview is still loading, bail out instead of holding the
     * queue worker until the timeout.
     */
    private static function sessionStopRequested(string $sessionId): bool
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);

        return $session !== null && (bool) $session->stopRequested;
    }
}
