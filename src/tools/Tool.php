<?php

namespace markhuot\craftai\tools;

/**
 * Base class for craft-ai tools. Subclasses implement __invoke with strongly-typed
 * parameters. Reflection (plus optional #[\markhuot\craftai\attributes\Tool] and
 * #[\markhuot\craftai\attributes\Description] overrides) translates the signature
 * into JSON Schema for both the in-app agent loop and the MCP server.
 *
 * Tools may return any value; ToolRegistry coerces it into a ToolOutput. Throw
 * an exception (or return a ToolOutput with isError=true) to signal a failure.
 */
abstract class Tool
{
    // Subclasses MUST implement: public function __invoke(...): mixed
}
