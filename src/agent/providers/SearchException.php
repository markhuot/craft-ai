<?php

namespace markhuot\craftai\agent\providers;

use RuntimeException;
use Throwable;

/**
 * Thrown when a web search backend fails (network error, auth rejection,
 * upstream rate limit, malformed response, etc.). The tool converts these
 * into ToolOutput errors so the agent can react instead of crashing the turn.
 */
class SearchException extends RuntimeException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
