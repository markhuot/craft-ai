<?php

namespace markhuot\craftai\agent\providers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * Thin wrapper around OpenAI's `/v1/images/generations` endpoint. Accepts the
 * provider-native request body so each tool can expose the surface its model
 * actually understands (gpt-image-1 has different parameters than dall-e-3,
 * etc.) without lossy normalization. The wrapper handles auth, response
 * parsing, base64 decoding, and mapping HTTP/policy errors to
 * {@see ImageGenerationException}.
 */
class OpenAiImageProvider
{
    private readonly ClientInterface $http;

    public function __construct(
        string $apiKey,
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
            'base_uri' => rtrim($baseUrl ?? 'https://api.openai.com', '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'content-type' => 'application/json',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $body  Request body. Must include `model`
     *         and `prompt`; other parameters (size, quality, background,
     *         output_format, moderation, etc.) are model-specific and pass
     *         straight through.
     */
    public function generate(array $body): GeneratedImage
    {
        try {
            $response = $this->http->request('POST', 'v1/images/generations', ['json' => $body]);
        } catch (BadResponseException $e) {
            throw $this->mapHttpError($e);
        } catch (GuzzleException $e) {
            throw new ImageGenerationException(
                "Image generation failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        try {
            /** @var array{data?: list<array{b64_json?: string, revised_prompt?: string}>} $payload */
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImageGenerationException(
                'Image generation failed: provider returned invalid JSON.',
                previous: $e,
            );
        }

        $first = $payload['data'][0] ?? null;
        $b64 = is_array($first) ? ($first['b64_json'] ?? null) : null;
        if (! is_string($b64) || $b64 === '') {
            throw new ImageGenerationException(
                'Image generation succeeded but the response contained no image data.',
            );
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new ImageGenerationException(
                'Image generation failed: provider returned invalid base64.',
            );
        }

        $revisedRaw = is_array($first) ? ($first['revised_prompt'] ?? null) : null;
        $revised = is_string($revisedRaw) && $revisedRaw !== '' ? $revisedRaw : null;

        // OpenAI's image API doesn't echo back the chosen output format, so we
        // detect from the request body. gpt-image-1 / dall-e-3 default to
        // PNG when output_format isn't set.
        $format = $body['output_format'] ?? 'png';
        $mime = match ($format) {
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return new GeneratedImage(
            bytes: $decoded,
            mimeType: $mime,
            revisedPrompt: $revised,
        );
    }

    private function mapHttpError(BadResponseException $e): ImageGenerationException
    {
        $body = (string) $e->getResponse()->getBody();
        $message = $e->getMessage();
        $isModeration = false;

        try {
            /** @var array{error?: array{message?: string, code?: string, type?: string}} $payload */
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $error = $payload['error'] ?? null;
            if (is_array($error)) {
                $providedMessage = $error['message'] ?? null;
                if (is_string($providedMessage) && $providedMessage !== '') {
                    $message = $providedMessage;
                }
                $code = is_string($error['code'] ?? null) ? $error['code'] : '';
                $type = is_string($error['type'] ?? null) ? $error['type'] : '';
                $isModeration = str_contains($code, 'moderation')
                    || str_contains($code, 'safety')
                    || $type === 'image_generation_user_error';
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
