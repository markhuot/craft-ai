<?php

namespace markhuot\craftai\agent\providers;

use RuntimeException;
use Throwable;

/**
 * Wraps a provider-side image generation failure with a user-readable message.
 * Tools should catch this and surface $userMessage in a ToolOutput so the
 * agent receives the model-readable text rather than raw HTTP transport detail.
 *
 * `isModeration` flags content-policy rejections so the calling tool can
 * encourage the agent to rephrase rather than retry verbatim.
 */
class ImageGenerationException extends RuntimeException
{
    public function __construct(
        string $userMessage,
        public readonly bool $isModeration = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }
}
