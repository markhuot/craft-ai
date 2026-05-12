<?php

use markhuot\craftai\agent\providers\SearchException;
use markhuot\craftai\agent\providers\SearchProvider;
use markhuot\craftai\agent\providers\SearchProviderRegistry;
use markhuot\craftai\agent\providers\SearchResult;
use markhuot\craftai\tools\SearchTheWeb;
use markhuot\craftai\tools\ToolOutput;

/**
 * Lightweight fake — lets each test assert what was asked of the provider
 * and how the tool shaped the response, without going near HTTP.
 */
function fakeProvider(string $name, callable $handler): SearchProvider
{
    return new class($name, $handler) implements SearchProvider {
        /** @var callable(string, int): (list<SearchResult>|SearchException) */
        private $handler;

        public function __construct(private readonly string $providerName, callable $handler)
        {
            $this->handler = $handler;
        }

        public function name(): string
        {
            return $this->providerName;
        }

        public function search(string $query, int $limit = 10): array
        {
            $result = ($this->handler)($query, $limit);
            if ($result instanceof SearchException) {
                throw $result;
            }

            return $result;
        }
    };
}

it('returns normalized results with a citation-friendly note', function () {
    $provider = fakeProvider('test', fn () => [
        new SearchResult('Alt text guide', 'https://webaim.org/techniques/alttext/', 'How to write good alt text.'),
        new SearchResult('WCAG 1.1.1', 'https://www.w3.org/TR/WCAG21/#non-text-content', 'Non-text Content success criterion.'),
    ]);
    $tool = new SearchTheWeb(new SearchProviderRegistry([$provider]));

    $output = $tool('alt text best practices');

    expect($output)->toBeArray();
    expect($output['data']['provider'])->toBe('test');
    expect($output['data']['query'])->toBe('alt text best practices');
    expect($output['data']['results'])->toHaveCount(2);
    expect($output['data']['results'][0])->toBe([
        'title' => 'Alt text guide',
        'url' => 'https://webaim.org/techniques/alttext/',
        'snippet' => 'How to write good alt text.',
    ]);
    expect($output['_notes'])->toContain('Found 2 result');
    expect($output['_notes'])->toContain('fetch_webpage');
    expect($output['_notes'])->toContain('cite');
});

it('reports an empty result set without erroring', function () {
    $provider = fakeProvider('test', fn () => []);
    $tool = new SearchTheWeb(new SearchProviderRegistry([$provider]));

    $output = $tool('zzzzz no hits zzzzz');

    expect($output)->toBeArray();
    expect($output['data']['results'])->toBe([]);
    expect($output['_notes'])->toContain('No results');
});

it('forwards the limit argument to the provider', function () {
    $captured = ['query' => null, 'limit' => null];
    $provider = fakeProvider('test', function (string $q, int $limit) use (&$captured): array {
        $captured = ['query' => $q, 'limit' => $limit];

        return [];
    });
    $tool = new SearchTheWeb(new SearchProviderRegistry([$provider]));

    $tool('alt text', limit: 5);

    expect($captured['query'])->toBe('alt text');
    expect($captured['limit'])->toBe(5);
});

it('picks the requested provider when multiple are configured', function () {
    $brave = fakeProvider('brave', fn () => [
        new SearchResult('Brave hit', 'https://example.com/b', 'b'),
    ]);
    $ddg = fakeProvider('duckduckgo', fn () => [
        new SearchResult('DDG hit', 'https://example.com/d', 'd'),
    ]);
    $tool = new SearchTheWeb(new SearchProviderRegistry([$brave, $ddg]));

    $output = $tool('alt text', provider: 'duckduckgo');

    expect($output)->toBeArray();
    expect($output['data']['provider'])->toBe('duckduckgo');
    expect($output['data']['results'][0]['url'])->toBe('https://example.com/d');
});

it('falls back to the default provider when none is requested', function () {
    $brave = fakeProvider('brave', fn () => [
        new SearchResult('Brave hit', 'https://example.com/b', 'b'),
    ]);
    $ddg = fakeProvider('duckduckgo', fn () => [
        new SearchResult('DDG hit', 'https://example.com/d', 'd'),
    ]);
    $tool = new SearchTheWeb(new SearchProviderRegistry([$brave, $ddg], defaultName: 'duckduckgo'));

    $output = $tool('alt text');

    expect($output)->toBeArray();
    expect($output['data']['provider'])->toBe('duckduckgo');
});

it('returns an error when the agent asks for an unconfigured provider', function () {
    $provider = fakeProvider('duckduckgo', fn () => []);
    $tool = new SearchTheWeb(new SearchProviderRegistry([$provider]));

    $output = $tool('alt text', provider: 'bing');

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Unknown search provider');
    expect($output->text)->toContain('duckduckgo');
});

it('surfaces SearchException as a tool error', function () {
    $provider = fakeProvider('test', fn () => new SearchException('upstream is down'));
    $tool = new SearchTheWeb(new SearchProviderRegistry([$provider]));

    $output = $tool('alt text');

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('upstream is down');
});

it('rejects an empty query before hitting the provider', function () {
    $called = false;
    $provider = fakeProvider('test', function () use (&$called): array {
        $called = true;

        return [];
    });
    $tool = new SearchTheWeb(new SearchProviderRegistry([$provider]));

    $output = $tool('   ');

    expect($output)->toBeInstanceOf(ToolOutput::class);
    expect($output->isError)->toBeTrue();
    expect($called)->toBeFalse();
});
