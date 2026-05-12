<?php

namespace markhuot\craftai\tools;

use markhuot\craftai\agent\providers\SearchException;
use markhuot\craftai\agent\providers\SearchProviderRegistry;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;

/**
 * Search the web and return a list of result links the agent can fetch with
 * `fetch_webpage`. Intended for research-style prompts ("find sources on X
 * and cite them") — the agent runs a search, then fetches the most promising
 * URLs to read the actual content.
 *
 * Which backend runs the query is controlled by `searchProviders` in
 * `config/craft-ai.php`. The `provider` parameter picks among configured
 * providers when more than one is enabled; with a single provider configured,
 * it can be omitted entirely.
 */
class SearchTheWeb extends Tool
{
    public const KIND = ToolKind::Read;

    public function __construct(
        private readonly SearchProviderRegistry $registry,
    ) {}

    /**
     * @return array{_notes: string, data: array{query: string, provider: string, results: list<array{title: string, url: string, snippet: string}>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Search query — phrase it the way you would type into a search engine.')]
        #[Validate('string', max: 2000)]
        string $query,
        #[Description('Maximum number of results to return (1–20, default 10). Upstream caps may apply: Google returns at most 10 per call.')]
        #[Validate('integer', min: 1, max: 20)]
        ?int $limit = 10,
        #[Description('Which configured search provider to use (e.g. "google", "duckduckgo"). Omit to use the project default when only one provider is configured.')]
        ?string $provider = null,
    ): array|ToolOutput {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return new ToolOutput('Validation failed: query must not be empty.', isError: true);
        }

        try {
            $resolved = $this->registry->get($provider);
            $results = $resolved->search($trimmed, $limit ?? 10);
        } catch (SearchException $e) {
            return new ToolOutput($e->getMessage(), isError: true);
        }

        $data = array_map(static fn ($r): array => $r->toArray(), $results);

        if ($data === []) {
            $notes = "No results from {$resolved->name()} for \"{$trimmed}\". Try rephrasing the query or pick a different provider with the `provider` argument.";
        } else {
            $count = count($data);
            $notes = "Found {$count} result(s) via {$resolved->name()} for \"{$trimmed}\". Call fetch_webpage with one of the urls to read its contents — cite each source you draw from.";
        }

        return [
            '_notes' => $notes,
            'data' => [
                'query' => $trimmed,
                'provider' => $resolved->name(),
                'results' => $data,
            ],
        ];
    }
}
