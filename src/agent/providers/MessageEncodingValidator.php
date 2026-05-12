<?php

namespace markhuot\craftai\agent\providers;

/**
 * Final UTF-8 gate before a provider POSTs a chat body. Earlier layers
 * (FetchWebpage normalization, ToolRegistry::coerce scrubbing, saveMessage's
 * JSON_INVALID_UTF8_SUBSTITUTE) should already have scrubbed every byte by the
 * time we get here — this is the safety net that catches anything that slips
 * through and converts it into a domain exception with a useful location hint,
 * instead of Guzzle's opaque "json_encode error" deep in applyOptions().
 *
 * Validates recursively and short-circuits on the first bad string so we don't
 * scan the whole tree for every send.
 */
final class MessageEncodingValidator
{
    /**
     * @param array<string, mixed> $body  The fully-assembled request body
     *        about to be passed to Guzzle as ['json' => $body]. Anthropic
     *        and OpenAI shapes are both just nested arrays of strings/ints, so
     *        a generic recursive walk is sufficient.
     *
     * @throws InvalidMessageEncodingException
     */
    public static function assertValid(array $body): void
    {
        $path = self::findInvalidPath($body, '');
        if ($path === null) {
            return;
        }

        // Best-effort: walk the messages list (if present) and find a
        // tool_call_id / tool_use_id at the same prefix so the exception can
        // blame the right tool turn. Misses gracefully when the bad bytes
        // aren't inside a tool-shaped block.
        $toolUseId = self::extractToolUseId($body, $path);

        throw new InvalidMessageEncodingException($path, $toolUseId);
    }

    private static function findInvalidPath(mixed $value, string $path): ?string
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? null : ($path === '' ? '$' : $path);
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path.'.'.(string) $key;
            $found = self::findInvalidPath($child, $childPath);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function extractToolUseId(array $body, string $path): ?string
    {
        // path looks like "messages.12.content.0.text". Walk down the same path
        // and collect any tool_call_id / tool_use_id we pass on the way — the
        // last one wins, which gives us the most specific tool turn.
        $segments = explode('.', $path);
        $cursor = $body;
        $lastId = null;

        foreach ($segments as $segment) {
            if (! is_array($cursor)) {
                break;
            }

            foreach (['tool_call_id', 'tool_use_id'] as $idKey) {
                if (isset($cursor[$idKey]) && is_string($cursor[$idKey])) {
                    $lastId = $cursor[$idKey];
                }
            }

            if (! array_key_exists($segment, $cursor) && ! array_key_exists((int) $segment, $cursor)) {
                break;
            }

            $cursor = $cursor[$segment] ?? $cursor[(int) $segment] ?? null;
        }

        return $lastId;
    }
}
