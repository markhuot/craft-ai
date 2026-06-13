<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\GeminiImageProvider;
use markhuot\craftai\agent\providers\ImageGenerationException;

class GeminiImageCapture
{
    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    public GeminiImageProvider $provider;

    /**
     * @param  Response|list<Response>  $responses
     */
    public function __construct(Response|array $responses, string $model = 'gemini-2.5-flash-image')
    {
        $queue = is_array($responses) ? $responses : [$responses];
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $this->provider = new GeminiImageProvider('test-key', $model, $client);
    }
}

function geminiOk(?string $b64 = null, string $mime = 'image/png'): Response
{
    $payload = [
        'candidates' => [[
            'content' => [
                'parts' => [
                    ['text' => 'here you go'],
                    ['inlineData' => [
                        'mimeType' => $mime,
                        'data' => $b64 ?? base64_encode("\x89PNG fake bytes"),
                    ]],
                ],
            ],
            'finishReason' => 'STOP',
        ]],
    ];

    return new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
}

it('forwards the request body verbatim to {model}:generateContent', function () {
    $cap = new GeminiImageCapture(geminiOk());

    $cap->provider->generate([
        'contents' => [['parts' => [['text' => 'a friendly cat']]]],
        'generationConfig' => [
            'responseModalities' => ['IMAGE', 'TEXT'],
            'imageConfig' => ['aspectRatio' => '16:9'],
        ],
    ]);

    expect((string) $cap->history[0]['request']->getUri())
        ->toContain('v1beta/models/gemini-2.5-flash-image:generateContent');
    $sent = json_decode((string) $cap->history[0]['request']->getBody(), true);
    expect($sent['contents'][0]['parts'][0]['text'])->toBe('a friendly cat');
    expect($sent['generationConfig']['responseModalities'])->toBe(['IMAGE', 'TEXT']);
    expect($sent['generationConfig']['imageConfig']['aspectRatio'])->toBe('16:9');
});

it('decodes Gemini inline image data into raw bytes', function () {
    $cap = new GeminiImageCapture(geminiOk(base64_encode('GEMINI_BYTES')));

    $result = $cap->provider->generate(['contents' => [['parts' => [['text' => 'cat']]]]]);

    expect($result->bytes)->toBe('GEMINI_BYTES');
    expect($result->mimeType)->toBe('image/png');
});

it('also reads inline_data with snake_case keys for older Gemini responses', function () {
    $payload = [
        'candidates' => [[
            'content' => ['parts' => [
                ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode('OLD')]],
            ]],
            'finishReason' => 'STOP',
        ]],
    ];
    $cap = new GeminiImageCapture(new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));

    $result = $cap->provider->generate(['contents' => [['parts' => [['text' => 'cat']]]]]);

    expect($result->bytes)->toBe('OLD');
    expect($result->mimeType)->toBe('image/jpeg');
});

it('flags moderation when finishReason is SAFETY', function () {
    $payload = ['candidates' => [['finishReason' => 'SAFETY']]];
    $cap = new GeminiImageCapture(new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));

    try {
        $cap->provider->generate(['contents' => [['parts' => [['text' => 'forbidden']]]]]);
        expect(false)->toBeTrue('should have thrown');
    } catch (ImageGenerationException $e) {
        expect($e->isModeration)->toBeTrue();
        expect($e->getMessage())->toContain('content policy');
        expect($e->getMessage())->toContain('SAFETY');
    }
});

it('throws when Gemini returns no inline image part (silent refusal)', function () {
    $payload = [
        'candidates' => [['content' => ['parts' => [['text' => 'I cannot help with that.']]], 'finishReason' => 'STOP']],
    ];
    $cap = new GeminiImageCapture(new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));

    try {
        $cap->provider->generate(['contents' => [['parts' => [['text' => '???']]]]]);
        expect(false)->toBeTrue('should have thrown');
    } catch (ImageGenerationException $e) {
        expect($e->getMessage())->toContain('no inline image data');
    }
});

it('flags moderation when the HTTP error message contains safety/policy keywords', function () {
    $body = json_encode([
        'error' => [
            'code' => 400,
            'message' => 'Request was blocked due to safety policy violation.',
            'status' => 'FAILED_PRECONDITION',
        ],
    ], JSON_THROW_ON_ERROR);
    $cap = new GeminiImageCapture(new Response(400, [], $body));

    try {
        $cap->provider->generate(['contents' => [['parts' => [['text' => 'forbidden']]]]]);
        expect(false)->toBeTrue('should have thrown');
    } catch (ImageGenerationException $e) {
        expect($e->isModeration)->toBeTrue();
        expect($e->getMessage())->toContain('content policy');
    }
});

it('surfaces a 400 error message from the Google error envelope', function () {
    $body = json_encode([
        'error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
        ],
    ], JSON_THROW_ON_ERROR);
    $cap = new GeminiImageCapture(new Response(400, [], $body));

    try {
        $cap->provider->generate(['contents' => [['parts' => [['text' => 'cat']]]]]);
        expect(false)->toBeTrue('should have thrown');
    } catch (ImageGenerationException $e) {
        expect($e->isModeration)->toBeFalse();
        expect($e->getMessage())->toContain('API key not valid');
    }
});
