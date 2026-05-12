<?php

namespace markhuot\craftai\agent\providers;

/**
 * One web search hit. Providers normalize their native response shapes into
 * this DTO so the search tool — and the agent above it — sees a consistent
 * surface no matter which backend is configured.
 */
class SearchResult
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet,
    ) {}

    /**
     * @return array{title: string, url: string, snippet: string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
        ];
    }
}
