<?php

namespace markhuot\craftai\agent\providers;

/**
 * Common surface for web search backends (Google Programmable Search,
 * DuckDuckGo, etc.). Implementations translate the request to whatever the
 * upstream expects and normalize the response into {@see SearchResult}
 * objects, throwing {@see SearchException} on failure.
 *
 * Multiple providers may be configured at once; the agent picks which to use
 * via the `search_the_web` tool's `provider` parameter (the
 * {@see SearchProviderRegistry} resolves a name to the right instance).
 */
interface SearchProvider
{
    /**
     * Stable provider key (e.g. `google`, `duckduckgo`) used both as the
     * config-file key and as the tool's `provider` argument.
     */
    public function name(): string;

    /**
     * @param  int  $limit  Maximum results to return. Implementations may
     *         clamp this to the upstream's per-request ceiling (Google CSE
     *         caps at 10; DuckDuckGo's HTML page yields ~20).
     * @return list<SearchResult>
     */
    public function search(string $query, int $limit = 10): array;
}
