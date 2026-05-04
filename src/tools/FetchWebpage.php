<?php

namespace markhuot\craftai\tools;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use markhuot\craftai\attributes\Description;

/**
 * Fetch a webpage by URL and return its contents. When the target URL's host
 * matches the current request's host, the current request's cookies are
 * forwarded so previewable/authenticated pages work transparently. Cookies
 * are never sent to a different host.
 *
 * By default the response is reduced to plain text; pass `fullHtml: true` to
 * get the raw HTML body instead.
 */
class FetchWebpage extends Tool
{
    public function __construct(
        private readonly ?Client $client = null,
    ) {}

    public function __invoke(
        #[Description('Absolute URL to fetch (http:// or https://).')]
        string $url,
        #[Description('Return raw HTML instead of extracted plain text.')]
        bool $fullHtml = false,
    ): ToolOutput {
        if (! preg_match('#^https?://#i', $url)) {
            return new ToolOutput('Validation failed: url must start with http:// or https://', isError: true);
        }

        $client = $this->client ?? Craft::createGuzzleClient();

        try {
            $headers = ['User-Agent' => 'craft-ai/FetchWebpage'];
            if (self::isSameHostAsRequest($url)) {
                $cookieHeader = self::buildCookieHeader();
                if ($cookieHeader !== '') {
                    $headers['Cookie'] = $cookieHeader;
                }
            }

            $response = $client->request('GET', $url, [
                'headers' => $headers,
                'allow_redirects' => true,
                'http_errors' => false,
                'timeout' => 30,
            ]);
        } catch (GuzzleException $e) {
            return new ToolOutput("Failed to fetch {$url}: {$e->getMessage()}", isError: true);
        }

        $body = (string) $response->getBody();
        $status = $response->getStatusCode();

        if ($status >= 400) {
            return new ToolOutput("HTTP {$status} fetching {$url}", isError: true);
        }

        $output = $fullHtml ? $body : self::extractText($body);

        return new ToolOutput($output);
    }

    private static function isSameHostAsRequest(string $url): bool
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return false;
        }

        $targetHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($targetHost) || $targetHost === '') {
            return false;
        }

        $currentHost = $request->getHostName();
        if (! is_string($currentHost) || $currentHost === '') {
            return false;
        }

        return strcasecmp($targetHost, $currentHost) === 0;
    }

    private static function buildCookieHeader(): string
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return '';
        }

        $parts = [];
        foreach (Craft::$app->getRequest()->getCookies() as $cookie) {
            $parts[] = $cookie->name.'='.rawurlencode((string) $cookie->value);
        }

        return implode('; ', $parts);
    }

    private static function extractText(string $html): string
    {
        $stripped = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($stripped);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
