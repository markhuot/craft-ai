<?php

namespace markhuot\craftai\agent\providers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * Thin wrapper around Gemini's `:generateContent` endpoint for image-capable
 * models (e.g. `gemini-2.5-flash-image`, the "Nano Banana" model).
 * Accepts the provider-native request body so the calling tool can expose
 * Gemini's actual surface (`responseModalities`, `imageConfig.aspectRatio`,
 * multi-part inputs for image editing) instead of a lossy normalization.
 *
 * Google's OpenAI-compatibility shim only covers chat completions and
 * embeddings, so we can't share {@see OpenAiImageProvider} with a baseUrl
 * swap — the request/response shapes are different.
 */
class GeminiImageProvider
{
    private readonly ClientInterface $http;

    public function __construct(
        string $apiKey,
        private readonly string $model = 'gemini-2.5-flash-image',
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
            'base_uri' => rtrim($baseUrl ?? 'https://generativelanguage.googleapis.com', '/').'/',
            'headers' => [
                'x-goog-api-key' => $apiKey,
                'content-type' => 'application/json',
            ],
        ]);
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param  array<string, mixed>  $body  Gemini-native request body. Must
     *         include `contents`; tools that want image output should also
     *         set `generationConfig.responseModalities` to include `IMAGE`.
     */
    public function generate(array $body): GeneratedImage
    {
        try {
            $response = $this->http->request('POST', "v1beta/models/{$this->model}:generateContent", ['json' => $body]);
        } catch (BadResponseException $e) {
            throw $this->mapHttpError($e);
        } catch (GuzzleException $e) {
            throw new ImageGenerationException(
                "Image generation failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImageGenerationException(
                'Image generation failed: provider returned invalid JSON.',
                previous: $e,
            );
        }

        $candidates = is_array($payload['candidates'] ?? null) ? $payload['candidates'] : [];
        $first = $candidates[0] ?? null;

        // Gemini surfaces a safety block as `finishReason: SAFETY` (or similar)
        // on the candidate, often with no parts at all. Detect this before
        // parsing parts so we can throw a moderation-flagged error.
        $finishReason = is_array($first) ? ($first['finishReason'] ?? '') : '';
        if (is_string($finishReason) && in_array($finishReason, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'IMAGE_SAFETY'], true)) {
            throw new ImageGenerationException(
                "Image generation rejected by content policy: Gemini returned finishReason={$finishReason}.",
                isModeration: true,
            );
        }

        $content = is_array($first) && is_array($first['content'] ?? null) ? $first['content'] : [];
        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (! is_array($inline)) {
                continue;
            }
            $b64 = $inline['data'] ?? null;
            $mime = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';
            if (! is_string($b64) || $b64 === '') {
                continue;
            }
            $decoded = base64_decode($b64, true);
            if ($decoded === false) {
                throw new ImageGenerationException('Image generation failed: Gemini returned invalid base64.');
            }

            return new GeneratedImage(
                bytes: $decoded,
                mimeType: is_string($mime) && $mime !== '' ? $mime : 'image/png',
            );
        }

        throw new ImageGenerationException(
            'Image generation succeeded but Gemini returned no inline image data. The prompt may have been declined silently.',
        );
    }

    private function mapHttpError(BadResponseException $e): ImageGenerationException
    {
        $body = (string) $e->getResponse()->getBody();
        $message = $e->getMessage();
        $isModeration = false;

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $error = $payload['error'] ?? null;
            if (is_array($error)) {
                $errMsg = $error['message'] ?? null;
                if (is_string($errMsg) && $errMsg !== '') {
                    $message = $errMsg;
                }
                $status = is_string($error['status'] ?? null) ? $error['status'] : '';
                $isModeration = $status === 'FAILED_PRECONDITION'
                    || str_contains(strtolower($message), 'safety')
                    || str_contains(strtolower($message), 'policy')
                    || str_contains(strtolower($message), 'blocked');
            }
        } catch (\JsonException) {
            // Fall through with the raw Guzzle message.
        }

        $prefix = $isModeration
            ? 'Image generation rejected by content policy: '
            : 'Image generation failed: ';

        return new ImageGenerationException(
            $prefix.$message,
            isModeration: $isModeration,
            previous: $e,
        );
    }
}
