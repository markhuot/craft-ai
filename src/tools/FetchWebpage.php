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
    public const KIND = ToolKind::Read;

    public function __construct(
        private readonly ?Client $client = null,
    ) {}

    /**
     * @return array{_notes: string, data: array{url: string, status: int, contentType: string, format: string, content: string}}|ToolOutput
     */
    public function __invoke(
        #[Description('Absolute URL to fetch (http:// or https://).')]
        string $url,
        #[Description('Return raw HTML instead of extracted plain text.')]
        bool $fullHtml = false,
    ): array|ToolOutput {
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
        $contentType = $response->getHeaderLine('Content-Type');
        $normalized = self::normalizeToUtf8($body, $contentType);
        $body = $normalized['body'];
        $status = $response->getStatusCode();

        $output = $fullHtml ? $body : self::extractText($body);

        // Belt and suspenders: extractText runs regex/strip_tags on the
        // scrubbed body, and a buggy entity decode could re-introduce a stray
        // byte. Scrub the post-processed output too and roll its replacement
        // count into the same breadcrumb.
        $output = mb_scrub($output, 'UTF-8');

        $encodingNote = self::encodingBreadcrumb($normalized['replacedBytes'], $normalized['sourceEncoding']);

        if ($status >= 400) {
            $message = "HTTP {$status} fetching {$url}";
            $snippet = self::truncate($output, 8000);
            if ($snippet !== '') {
                $message .= "\n\n".$snippet;
            }
            if ($encodingNote !== '') {
                $message .= "\n\n".$encodingNote;
            }

            return new ToolOutput($message, isError: true);
        }

        $format = $fullHtml ? 'html' : 'text';
        $bytes = strlen($output);
        $contentTypeForNote = $contentType !== '' ? $contentType : 'unknown';
        $notes = "Fetched {$bytes} bytes of {$format} from {$url} (HTTP {$status}, Content-Type: {$contentTypeForNote}). Re-fetch with fullHtml=true to see raw markup including scripts/styles.";
        if ($encodingNote !== '') {
            $notes .= ' '.$encodingNote;
        }

        return [
            '_notes' => $notes,
            'data' => [
                'url' => $url,
                'status' => $status,
                'contentType' => $contentType,
                'format' => $format,
                'content' => $output,
            ],
        ];
    }

    private static function encodingBreadcrumb(int $replacedBytes, string $sourceEncoding): string
    {
        if ($replacedBytes <= 0) {
            return '';
        }

        $sourceClause = $sourceEncoding !== '' && strcasecmp($sourceEncoding, 'UTF-8') !== 0
            ? " (page was declared as {$sourceEncoding})"
            : '';

        return "Note: {$replacedBytes} byte(s) of invalid UTF-8 from the source page were replaced with U+FFFD{$sourceClause}.";
    }

    private static function truncate(string $text, int $limit): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return mb_strcut($text, 0, $limit, 'UTF-8')."\n\n[truncated]";
    }

    /**
     * Coerce the response body to valid UTF-8. The agent loop persists tool
     * output via json_encode(JSON_THROW_ON_ERROR) and Anthropic/OpenAI APIs
     * expect UTF-8, so a page served as Windows-1252 (or with stray invalid
     * bytes) would otherwise kill the entire turn.
     *
     * Returns the scrubbed body plus a count of bytes that needed substitution
     * (after any source-encoding conversion). The caller renders that count
     * into the tool's `_notes` so the agent sees a visible breadcrumb rather
     * than silently mangled content.
     *
     * @return array{body: string, replacedBytes: int, sourceEncoding: string}
     */
    private static function normalizeToUtf8(string $body, string $contentType): array
    {
        $encoding = self::detectEncodingFromHeader($contentType)
            ?? self::detectEncodingFromHtml($body)
            ?? 'UTF-8';

        if (strcasecmp($encoding, 'UTF-8') !== 0) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $encoding);
            if (is_string($converted)) {
                $body = $converted;
            }
        }

        $preLength = strlen($body);
        $isValidBeforeScrub = mb_check_encoding($body, 'UTF-8');
        $scrubbed = mb_scrub($body, 'UTF-8');

        // mb_scrub replaces each bad byte with U+FFFD (3 bytes), so the byte
        // count can grow. Use the symmetric difference, then clamp to >=1 when
        // we know something was invalid — so a same-length substitution still
        // surfaces in the breadcrumb.
        $replacedBytes = abs($preLength - strlen($scrubbed));
        if (! $isValidBeforeScrub && $replacedBytes === 0) {
            $replacedBytes = 1;
        }

        return [
            'body' => $scrubbed,
            'replacedBytes' => $replacedBytes,
            'sourceEncoding' => $encoding,
        ];
    }

    private static function detectEncodingFromHeader(string $contentType): ?string
    {
        if (preg_match('/charset\s*=\s*"?([^";\s]+)/i', $contentType, $m) === 1) {
            return self::canonicalEncoding($m[1]);
        }

        return null;
    }

    private static function detectEncodingFromHtml(string $body): ?string
    {
        // Meta tags live in <head>; cap the scan so a giant body doesn't
        // waste cycles in a regex that won't match anything after the head.
        $head = substr($body, 0, 4096);

        if (preg_match('/<meta[^>]+charset\s*=\s*"?([^"\'\s>]+)/i', $head, $m) === 1) {
            return self::canonicalEncoding($m[1]);
        }

        if (preg_match('/<meta[^>]+http-equiv\s*=\s*"?content-type"?[^>]+content\s*=\s*"[^"]*charset=([^"\s;]+)/i', $head, $m) === 1) {
            return self::canonicalEncoding($m[1]);
        }

        return null;
    }

    private static function canonicalEncoding(string $name): string
    {
        $name = strtoupper(trim($name, " \t\n\r\0\x0B\"'"));

        // Per the WHATWG Encoding standard, pages labeled ISO-8859-1 / Latin-1
        // are actually decoded as Windows-1252 by browsers; do the same so a
        // curly quote at 0x93 doesn't get mangled into a control character.
        return match ($name) {
            'LATIN-1', 'LATIN1', 'ISO-8859-1' => 'Windows-1252',
            default => $name,
        };
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
