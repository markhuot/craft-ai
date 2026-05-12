<?php

namespace markhuot\craftai\agent\providers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * Scrapes search.brave.com for organic web results. Brave Search runs its
 * own independent index (no Google/Bing reseller) and — unlike Google or
 * Bing — still serves real server-rendered HTML to a normal browser UA,
 * which is why this exists in place of a hypothetical GoogleSearchProvider.
 *
 * Brave uses Svelte, so most CSS class names are suffixed with rotating
 * `svelte-XXXX` hashes. We anchor the parser on Brave's `data-type="web"`
 * and `data-pos="N"` attributes instead — those are semantic markers that
 * survive Svelte recompiles. Class names without svelte suffixes (`title`,
 * `generic-snippet`, `content`) are also durable and used as secondary hooks.
 *
 * Brave has a paid JSON API for production use (https://api.search.brave.com)
 * — if this scraper becomes flaky or you exceed casual research traffic,
 * swap to that.
 */
class BraveSearchProvider implements SearchProvider
{
    // search.brave.com still serves HTML to anything looking like a real
    // browser. The UA is set per-request (not on the client constructor) so
    // an injected client in tests / custom DI still gets the right headers.
    private const REQUEST_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            .'AppleWebKit/537.36 (KHTML, like Gecko) '
            .'Chrome/124.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

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
            'base_uri' => rtrim($baseUrl ?? 'https://search.brave.com', '/').'/',
        ]);
    }

    public function name(): string
    {
        return 'brave';
    }

    /**
     * @return list<SearchResult>
     */
    public function search(string $query, int $limit = 10): array
    {
        try {
            $response = $this->http->request('GET', 'search', [
                'headers' => self::REQUEST_HEADERS,
                'query' => [
                    'q' => $query,
                    // `source=web` skips the news/images/etc. clusters that
                    // would otherwise pad the result list.
                    'source' => 'web',
                ],
                'allow_redirects' => true,
            ]);
        } catch (BadResponseException $e) {
            throw self::mapHttpError($e);
        } catch (GuzzleException $e) {
            throw new SearchException(
                "Brave search failed: {$e->getMessage()}",
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
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        // `data-type="web"` is Brave's stable marker for an organic web
        // result. Other values ("ad", "cluster", "discussions", "news") get
        // filtered out by this alone, so we don't need a separate ad check.
        $blocks = $xpath->query('//div[@data-type="web"]');
        if ($blocks === false) {
            return [];
        }

        $results = [];
        $seenUrls = [];
        foreach ($blocks as $block) {
            if (! $block instanceof DOMElement) {
                continue;
            }

            $url = self::extractUrl($xpath, $block);
            if ($url === '') {
                continue;
            }
            if (isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;

            $title = self::extractTitle($xpath, $block);
            if ($title === '') {
                continue;
            }

            $snippet = self::extractSnippet($xpath, $block);

            $results[] = new SearchResult(
                title: $title,
                url: $url,
                snippet: $snippet,
            );

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * The first descendant anchor with an absolute http(s) href is the
     * result's primary link. Brave doesn't wrap outbound URLs in a redirect,
     * so the href is the real destination and we don't need to decode
     * anything.
     */
    private static function extractUrl(DOMXPath $xpath, DOMElement $block): string
    {
        $anchors = $xpath->query('.//a[@href]', $block);
        if ($anchors === false) {
            return '';
        }

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }
            $href = $anchor->getAttribute('href');
            if (preg_match('#^https?://#i', $href) !== 1) {
                continue;
            }
            // Brave occasionally surfaces favicon / image-CDN URLs as
            // anchors inside the result block (the favicon wrapper). Skip
            // anything pointing at Brave's own CDN.
            $host = parse_url($href, PHP_URL_HOST);
            if (is_string($host) && (
                str_ends_with($host, 'brave.com')
                || str_ends_with($host, 'search.brave.com')
            )) {
                continue;
            }

            return $href;
        }

        return '';
    }

    /**
     * Brave wraps the visible title in `<div class="title ...">` inside the
     * primary anchor. Prefer the `title` attribute if present (it's always
     * the full untruncated title); fall back to text content for older /
     * variant layouts.
     */
    private static function extractTitle(DOMXPath $xpath, DOMElement $block): string
    {
        $candidates = $xpath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' title ')]",
            $block,
        );
        if ($candidates !== false) {
            foreach ($candidates as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $attrTitle = $node->getAttribute('title');
                if ($attrTitle !== '') {
                    return self::normalizeWhitespace($attrTitle);
                }
                $text = self::normalizeWhitespace($node->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        // Last resort: text of the primary anchor.
        $anchors = $xpath->query('.//a[@href]', $block);
        if ($anchors !== false && $anchors->length > 0) {
            $first = $anchors->item(0);
            if ($first instanceof DOMElement) {
                return self::normalizeWhitespace($first->textContent);
            }
        }

        return '';
    }

    /**
     * The snippet lives in `<div class="generic-snippet ..."><div class="content ...">`.
     * Brave prefixes some snippets with a relative-date span
     * (`<span class="t-secondary">18 hours ago -</span>`); the textContent
     * we extract here includes that prefix, which is fine — it gives the
     * agent a useful recency hint without us having to parse dates.
     */
    private static function extractSnippet(DOMXPath $xpath, DOMElement $block): string
    {
        $contents = $xpath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' generic-snippet ')]"
            ."//*[contains(concat(' ', normalize-space(@class), ' '), ' content ')]",
            $block,
        );
        if ($contents !== false) {
            foreach ($contents as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $text = self::normalizeWhitespace($node->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        // Fallback for layout variants: longest descendant text that isn't
        // the title or the URL chrome.
        return self::longestText($xpath, $block);
    }

    private static function longestText(DOMXPath $xpath, DOMElement $block): string
    {
        $candidates = $xpath->query('.//div | .//p | .//span', $block);
        if ($candidates === false) {
            return '';
        }

        $best = '';
        foreach ($candidates as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            // Skip titles, URLs, and favicon chrome.
            $class = $node->getAttribute('class');
            if (str_contains($class, 'title')
                || str_contains($class, 'snippet-url')
                || str_contains($class, 'favicon')
            ) {
                continue;
            }
            $text = self::normalizeWhitespace($node->textContent);
            if (mb_strlen($text) < 50) {
                continue;
            }
            if (mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }

        return $best;
    }

    private static function normalizeWhitespace(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $collapsed);
    }

    private static function mapHttpError(BadResponseException $e): SearchException
    {
        $status = $e->getResponse()->getStatusCode();

        if ($status === 429 || $status === 503) {
            return new SearchException(
                "Brave search failed: HTTP {$status} (rate limited). "
                .'Wait a few minutes and try again, or switch to the duckduckgo provider.',
                previous: $e,
            );
        }

        return new SearchException(
            "Brave search failed: HTTP {$status}.",
            previous: $e,
        );
    }
}
