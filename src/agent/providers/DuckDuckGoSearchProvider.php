<?php

namespace markhuot\craftai\agent\providers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * DuckDuckGo doesn't ship a documented JSON web-results API — the Instant
 * Answer endpoint at api.duckduckgo.com only returns infobox-style answers,
 * not the SERP. This provider hits the HTML endpoint at
 * `https://html.duckduckgo.com/html/` (the one their official lite UI uses)
 * and parses the result list with DOMDocument.
 *
 * Trade-offs vs. {@see GoogleSearchProvider}:
 *  - No API key required, no daily quota — the entry-level "just enable it"
 *    option, which is why the example config lists it first.
 *  - HTML scraping is inherently fragile: if DuckDuckGo restructures the page
 *    we'll need to update the selectors here. The structure has been stable
 *    for years, but treat this as best-effort.
 */
class DuckDuckGoSearchProvider implements SearchProvider
{
    private readonly ClientInterface $http;

    public function __construct(
        ?ClientInterface $http = null,
        ?string $baseUrl = null,
    ) {
        if ($http !== null) {
            $this->http = $http;

            return;
        }

        $stack = HandlerStack::create();
        RetryMiddleware::attach($stack);
        $this->http = new Client([
            'handler' => $stack,
            'base_uri' => rtrim($baseUrl ?? 'https://html.duckduckgo.com', '/').'/',
            'headers' => [
                // DDG returns an empty page to obvious bots / no-UA clients —
                // a real-ish UA gets the regular result list.
                'User-Agent' => 'Mozilla/5.0 (compatible; craft-ai/SearchTheWeb)',
                'Accept' => 'text/html,application/xhtml+xml',
            ],
        ]);
    }

    public function name(): string
    {
        return 'duckduckgo';
    }

    /**
     * @return list<SearchResult>
     */
    public function search(string $query, int $limit = 10): array
    {
        try {
            $response = $this->http->request('POST', 'html/', [
                // POST form-encoded is what the HTML UI sends; GET also works
                // but POST avoids leaking the query into intermediate logs.
                'form_params' => ['q' => $query],
                'allow_redirects' => true,
            ]);
        } catch (BadResponseException $e) {
            throw new SearchException(
                "DuckDuckGo search failed: HTTP {$e->getResponse()->getStatusCode()}.",
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new SearchException(
                "DuckDuckGo search failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $html = (string) $response->getBody();

        return self::parseResults($html, $limit);
    }

    /**
     * @return list<SearchResult>
     */
    private static function parseResults(string $html, int $limit): array
    {
        if ($html === '') {
            return [];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // loadHTML wants a Content-Type hint to keep UTF-8 intact; the meta
        // charset prefix is the documented workaround.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        // DDG wraps each result in `<div class="result ...">`. The title and
        // URL live in the descendant `<a class="result__a">`; the snippet in
        // `<a class="result__snippet">` (or a `<div>` of the same class on
        // some result types).
        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' result ')]");
        if ($nodes === false) {
            return [];
        }

        $results = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $titleAnchor = self::firstByClass($xpath, $node, 'result__a');
            if (! $titleAnchor instanceof DOMElement) {
                continue;
            }

            $title = trim($titleAnchor->textContent);
            $href = $titleAnchor->getAttribute('href');
            $url = self::resolveUrl($href);
            if ($url === '') {
                continue;
            }

            $snippetEl = self::firstByClass($xpath, $node, 'result__snippet');
            $snippet = $snippetEl instanceof DOMElement ? trim($snippetEl->textContent) : '';

            $results[] = new SearchResult(
                title: $title !== '' ? $title : $url,
                url: $url,
                snippet: $snippet,
            );

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private static function firstByClass(DOMXPath $xpath, DOMElement $context, string $class): ?DOMElement
    {
        $found = $xpath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]",
            $context,
        );
        if ($found === false) {
            return null;
        }
        foreach ($found as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    /**
     * DDG wraps every outbound link in a `//duckduckgo.com/l/?uddg=<encoded>`
     * redirect — extract the real destination, otherwise the agent ends up
     * citing duckduckgo.com instead of the source. Direct https URLs (which
     * sometimes appear, e.g. for some ad-free hits) pass through unchanged.
     */
    private static function resolveUrl(string $href): string
    {
        if ($href === '') {
            return '';
        }

        // Protocol-relative redirect like `//duckduckgo.com/l/?uddg=...`.
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        $parts = parse_url($href);
        if (! is_array($parts)) {
            return '';
        }

        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if (($host === 'duckduckgo.com' || $host === 'www.duckduckgo.com')
            && ($path === '/l/' || $path === '/l')
        ) {
            parse_str($parts['query'] ?? '', $query);
            $target = $query['uddg'] ?? null;
            if (is_string($target) && $target !== '') {
                return $target;
            }

            return '';
        }

        // Direct hit — return as-is, but require an absolute http(s) URL so
        // relative DDG-internal links don't slip through as citations.
        if (preg_match('#^https?://#i', $href) !== 1) {
            return '';
        }

        return $href;
    }
}
