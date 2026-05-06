<?php

namespace markhuot\craftai\tools;

use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\PreviewRequestRecord;
use markhuot\craftai\records\SessionRecord;

/**
 * Read the contents of the URL currently loaded in the CP preview pane and
 * return it as text. By default the response is the iframe's rendered text
 * (closer to what a human reader sees); pass `fullHtml: true` to get the
 * raw outerHTML instead.
 *
 * CP-only: the preview pane only exists in the CP chat surface. If no
 * preview is open, or the loaded URL is cross-origin (so the iframe's
 * contents can't be read from JS), the tool returns an error and the agent
 * should fall back to `fetch_webpage`.
 */
class GetPreview extends Tool
{
    /**
     * Hard cap on bytes returned to the agent. CP edit pages can render
     * megabytes of HTML; piping all of that into the LLM context burns
     * tokens fast and (historically) overflowed the messages table column.
     * Beyond this cap we truncate and append a `[Truncated: …]` marker so
     * the model knows the content was clipped.
     */
    public const MAX_OUTPUT_BYTES = 200_000;

    public function __construct(
        private readonly PreviewService $preview = new PreviewService(),
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    public function __invoke(
        #[Description('Return the iframe\'s raw outerHTML instead of extracted plain text.')]
        bool $fullHtml = false,
        #[Description('Maximum seconds to wait for the front-end to read the iframe. Clamped to [5, 60]. Defaults to 10.')]
        int $timeoutSeconds = 10,
    ): ToolOutput {
        $sessionId = $this->context->getSessionId();
        if ($sessionId === null) {
            return new ToolOutput(
                'get_preview can only be invoked from inside an active chat session.',
                isError: true,
            );
        }

        $requestId = $this->preview->create(
            $sessionId,
            $this->context->getToolUseId(),
            PreviewRequestRecord::TYPE_GET,
            ['fullHtml' => $fullHtml],
        );

        $resolved = $this->preview->waitFor(
            $requestId,
            $timeoutSeconds,
            shouldAbort: static fn (): bool => self::sessionStopRequested($sessionId),
        );

        if ($resolved->status === PreviewRequestRecord::STATUS_ERRORED) {
            $payload = $this->preview->decodeResult($resolved);
            $message = is_string($payload['error'] ?? null)
                ? $payload['error']
                : 'Could not read the preview pane.';

            return new ToolOutput($message, isError: true);
        }

        $payload = $this->preview->decodeResult($resolved);
        $content = is_string($payload['content'] ?? null) ? $payload['content'] : '';

        if (strlen($content) > self::MAX_OUTPUT_BYTES) {
            $content = substr($content, 0, self::MAX_OUTPUT_BYTES)
                ."\n\n[Truncated: preview content exceeded ".self::MAX_OUTPUT_BYTES." bytes. Use fetch_webpage with a specific URL or call get_preview again with fullHtml: false for plain text.]";
        }

        return new ToolOutput($content);
    }

    private static function sessionStopRequested(string $sessionId): bool
    {
        $session = SessionRecord::findOne(['id' => $sessionId]);

        return $session !== null && (bool) $session->stopRequested;
    }
}
