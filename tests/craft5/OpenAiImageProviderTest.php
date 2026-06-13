<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\ImageGenerationException;
use markhuot\craftai\agent\providers\OpenAiImageProvider;

class OpenAiImageCapture
{
    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    public OpenAiImageProvider $provider;

    /**
     * @param  Response|list<Response>  $responses
     */
    public function __construct(Response|array $responses)
    {
        $queue = is_array($responses) ? $responses : [$responses];
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $this->provider = new OpenAiImageProvider('test-key', $client);
    }
}

function imageOk(?string $b64 = null, ?string $revised = null): Response
{
    $payload = ['data' => [['b64_json' => $b64 ?? base64_encode("\x89PNG fake bytes")]]];
    if ($revised !== null) {
        $payload['data'][0]['revised_prompt'] = $revised;
    }

    return new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
}

it('forwards the request body verbatim to /v1/images/generations', function () {
    $cap = new OpenAiImageCapture(imageOk());

    $cap->provider->generate([
        'model' => 'gpt-image-1',
        'prompt' => 'a friendly cat',
        'size' => '1024x1024',
        'quality' => 'high',
        'background' => 'transparent',
        'output_format' => 'png',
    ]);

    expect((string) $cap->history[0]['request']->getUri())->toContain('v1/images/generations');
    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    expect($sent)->toBe([
        'model' => 'gpt-image-1',
        'prompt' => 'a friendly cat',
        'size' => '1024x1024',
        'quality' => 'high',
        'background' => 'transparent',
        'output_format' => 'png',
    ]);
});

it('decodes b64_json into raw image bytes and surfaces revised_prompt when present', function () {
    $cap = new OpenAiImageCapture(imageOk(base64_encode('PNG_BYTES'), 'a fluffy gray cat sitting on a windowsill'));

    $result = $cap->provider->generate(['model' => 'gpt-image-1', 'prompt' => 'cat']);

    expect($result->bytes)->toBe('PNG_BYTES');
    expect($result->mimeType)->toBe('image/png');
    expect($result->revisedPrompt)->toBe('a fluffy gray cat sitting on a windowsill');
});

it('reports image/jpeg or image/webp when output_format requests them', function () {
    $cap = new OpenAiImageCapture([imageOk(), imageOk()]);

    $jpg = $cap->provider->generate(['model' => 'gpt-image-1', 'prompt' => 'x', 'output_format' => 'jpeg']);
    $webp = $cap->provider->generate(['model' => 'gpt-image-1', 'prompt' => 'x', 'output_format' => 'webp']);

    expect($jpg->mimeType)->toBe('image/jpeg');
    expect($webp->mimeType)->toBe('image/webp');
});

it('throws ImageGenerationException with isModeration=true when the API rejects on safety grounds', function () {
    $body = json_encode([
        'error' => [
            'message' => 'Your request was rejected as a result of our safety system.',
            'code' => 'moderation_blocked',
            'type' => 'image_generation_user_error',
        ],
    ], JSON_THROW_ON_ERROR);

    $cap = new OpenAiImageCapture(new Response(400, [], $body));

    try {
        $cap->provider->generate(['model' => 'gpt-image-1', 'prompt' => 'forbidden']);
        expect(false)->toBeTrue('should have thrown');
    } catch (ImageGenerationException $e) {
        expect($e->isModeration)->toBeTrue();
        expect($e->getMessage())->toContain('content policy');
        expect($e->getMessage())->toContain('safety system');
    }
});

it('throws with a generic prefix on non-moderation failures', function () {
    $body = json_encode(['error' => ['message' => 'Server overloaded', 'code' => 'overloaded']], JSON_THROW_ON_ERROR);
    $cap = new OpenAiImageCapture(new Response(500, [], $body));

    try {
        $cap->provider->generate(['model' => 'gpt-image-1', 'prompt' => 'cat']);
        expect(false)->toBeTrue('should have thrown');
    } catch (ImageGenerationException $e) {
        expect($e->isModeration)->toBeFalse();
        expect($e->getMessage())->toStartWith('Image generation failed:');
        expect($e->getMessage())->toContain('Server overloaded');
    }
});

it('throws when the provider response has no b64_json', function () {
    $cap = new OpenAiImageCapture(new Response(200, [], json_encode(['data' => [[]]], JSON_THROW_ON_ERROR)));

    try {
        $cap->provider->generate(['model' => 'gpt-image-1', 'prompt' => 'x']);
        expect(false)->toBeTrue('should have thrown');
    } catch (ImageGenerationException $e) {
        expect($e->getMessage())->toContain('no image data');
    }
});
