<?php

namespace markhuot\craftai\tools;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use markhuot\craftai\attributes\Description;

/**
 * Fetch an image by URL and surface its bytes to the agent as a multimodal
 * tool_result so vision-capable providers (Claude, gpt-4o family on Anthropic
 * shape) can actually see the image rather than just its URL. Useful for
 * inspecting screenshots, reference imagery, or any asset whose `url` you
 * already have (pair with `get_asset` to look one up first).
 *
 * Supported types: image/jpeg, image/png, image/gif, image/webp. Other content
 * types return an error. OpenAI-compatible providers can't render image
 * content inside tool messages, so on those providers only the text note
 * (URL, size, media type) gets through — the visual bytes are dropped at the
 * provider boundary.
 */
class GetImage extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * Cap the downloaded payload so a malicious or accidental link to a huge
     * file doesn't dump tens of MB of base64 into the conversation history.
     * Anthropic's hard ceiling is 5MB per image, so anything above that would
     * be rejected by the provider anyway.
     */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly ?Client $client = null,
    ) {}

    public function __invoke(
        #[Description('Absolute URL to the image to fetch (http:// or https://). Supports png, jpeg, gif, webp.')]
        string $url,
    ): ToolOutput {
        if (! preg_match('#^https?://#i', $url)) {
            return new ToolOutput('Validation failed: url must start with http:// or https://', isError: true);
        }

        $client = $this->client ?? Craft::createGuzzleClient();

        try {
            $response = $client->request('GET', $url, [
                'headers' => ['User-Agent' => 'craft-ai/GetImage'],
                'allow_redirects' => true,
                'http_errors' => false,
                'timeout' => 30,
            ]);
        } catch (GuzzleException $e) {
            return new ToolOutput("Failed to fetch {$url}: {$e->getMessage()}", isError: true);
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            return new ToolOutput("HTTP {$status} fetching {$url}", isError: true);
        }

        $bytes = (string) $response->getBody();
        $size = strlen($bytes);

        if ($size > self::MAX_BYTES) {
            $kb = (int) round($size / 1024);
            $maxKb = (int) round(self::MAX_BYTES / 1024);

            return new ToolOutput(
                "Image at {$url} is {$kb}KB, which exceeds the {$maxKb}KB limit. Resize or crop the source before re-trying.",
                isError: true,
            );
        }

        $contentType = $response->getHeaderLine('Content-Type');
        $mediaType = self::resolveMediaType($contentType, $bytes);

        if ($mediaType === null) {
            $reported = $contentType !== '' ? $contentType : 'unknown';

            return new ToolOutput(
                "URL did not return a supported image (Content-Type: {$reported}). Supported types: image/jpeg, image/png, image/gif, image/webp.",
                isError: true,
            );
        }

        $sizeKb = max(1, (int) round($size / 1024));
        $note = "Loaded {$sizeKb}KB {$mediaType} from {$url}. Vision-capable providers see the bytes attached below; OpenAI-compatible providers see only this note.";

        return new ToolOutput(
            text: $note,
            blocks: [
                ['type' => 'text', 'text' => $note],
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mediaType,
                        'data' => base64_encode($bytes),
                    ],
                ],
            ],
        );
    }

    /**
     * Trust the Content-Type header when it matches a supported image kind;
     * otherwise sniff the leading bytes. Servers commonly mislabel images as
     * application/octet-stream, and getimagesizefromstring() would needlessly
     * decode the pixel buffer just to read the MIME.
     */
    private static function resolveMediaType(string $contentType, string $bytes): ?string
    {
        $lower = strtolower(trim(explode(';', $contentType, 2)[0] ?? ''));
        $supported = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (in_array($lower, $supported, true)) {
            return $lower;
        }

        if ($lower === 'image/jpg') {
            return 'image/jpeg';
        }

        $head = substr($bytes, 0, 12);
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }
}
