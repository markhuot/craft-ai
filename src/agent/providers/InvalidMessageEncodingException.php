<?php

namespace markhuot\craftai\agent\providers;

use RuntimeException;

/**
 * Thrown by an LlmProvider when the message history it's about to POST contains
 * a string that isn't valid UTF-8. Catching this before Guzzle's json_encode
 * lets AgentJob surface a specific, actionable error to the user ("tool output
 * X contained invalid UTF-8") rather than an opaque GuzzleHttp\Exception\InvalidArgumentException.
 *
 * The $location hint identifies where in the assembled body the bad bytes were
 * found, e.g. "messages[12].content[0].text" or "messages[12].tool_call_id".
 * Carry $toolUseId when available so AgentJob can blame the right tool turn.
 */
class InvalidMessageEncodingException extends RuntimeException
{
    public function __construct(
        public readonly string $location,
        public readonly ?string $toolUseId = null,
    ) {
        $suffix = $toolUseId !== null ? " (tool_use_id={$toolUseId})" : '';
        parent::__construct("Message contains invalid UTF-8 at {$location}{$suffix}");
    }
}
