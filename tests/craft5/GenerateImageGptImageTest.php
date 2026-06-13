<?php

use craft\elements\Asset;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\OpenAiImageProvider;
use markhuot\craftai\tools\GenerateImageGptImage;
use markhuot\craftai\tools\ToolRegistry;
use markhuot\craftpest\factories\Volume;

beforeEach(function () {
    Volume::factory()->name('Uploads')->handle('uploads')->create();
    // Real bytes for a 1x1 PNG so Craft's asset pipeline doesn't emit
    // notices about invalid image data.
    $this->pngBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='
    );
});

/**
 * Assemble a registry with a stub OpenAiImageProvider whose mock client
 * returns the given response. Captures outgoing requests so tests can assert
 * on the body the tool sent to OpenAI.
 *
 * @return array{registry: ToolRegistry, history: array<int, array<string, mixed>>}
 */
function gptImageRegistry(Response $response): array
{
    /** @var array<int, array<string, mixed>> $history */
    $history = [];
    $stack = HandlerStack::create(new MockHandler([$response]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    Craft::$container->set(OpenAiImageProvider::class, new OpenAiImageProvider('test-key', $client));

    $registry = new ToolRegistry();
    $registry->register(GenerateImageGptImage::class);

    return ['registry' => $registry, 'history' => &$history];
}

it('passes provider-native gpt-image-1 parameters straight through to OpenAI', function () {
    $cap = gptImageRegistry(new Response(200, [], json_encode([
        'data' => [['b64_json' => base64_encode($this->pngBytes)]],
    ], JSON_THROW_ON_ERROR)));

    $output = $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'a friendly cat',
        'volume' => 'uploads',
        'size' => '1024x1536',
        'quality' => 'high',
        'background' => 'transparent',
        'outputFormat' => 'png',
        'moderation' => 'low',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $sent = json_decode((string) $cap['history'][0]['request']->getBody(), true);
    expect($sent['model'])->toBe('gpt-image-1');
    expect($sent['prompt'])->toBe('a friendly cat');
    expect($sent['size'])->toBe('1024x1536');
    expect($sent['quality'])->toBe('high');
    expect($sent['background'])->toBe('transparent');
    expect($sent['output_format'])->toBe('png');
    expect($sent['moderation'])->toBe('low');
});

it('switches to dall-e-3 mode when model=dall-e-3 (style + b64 response_format)', function () {
    $cap = gptImageRegistry(new Response(200, [], json_encode([
        'data' => [['b64_json' => base64_encode($this->pngBytes)]],
    ], JSON_THROW_ON_ERROR)));

    $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'a wide vista',
        'volume' => 'uploads',
        'model' => 'dall-e-3',
        'size' => '1792x1024',
        'quality' => 'hd',
        'style' => 'vivid',
        // Should NOT be sent for dall-e-3:
        'background' => 'transparent',
        'outputFormat' => 'png',
    ]);

    $sent = json_decode((string) $cap['history'][0]['request']->getBody(), true);
    expect($sent['model'])->toBe('dall-e-3');
    expect($sent['size'])->toBe('1792x1024');
    expect($sent['quality'])->toBe('hd');
    expect($sent['style'])->toBe('vivid');
    expect($sent['response_format'])->toBe('b64_json');
    // gpt-image-1-only params are dropped on the dall-e-3 path:
    expect($sent)->not->toHaveKey('background');
    expect($sent)->not->toHaveKey('output_format');
});

it('saves the returned bytes as a Craft asset with title from the prompt', function () {
    $cap = gptImageRegistry(new Response(200, [], json_encode([
        'data' => [['b64_json' => base64_encode($this->pngBytes)]],
    ], JSON_THROW_ON_ERROR)));

    $output = $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'a friendly cat',
        'volume' => 'uploads',
    ]);

    expect($output->isError)->toBeFalse($output->text);
    /** @var array{_notes: string, data: array{images: list<array{id: int, url: ?string, filename: string, mimeType: string}>}} $payload */
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKey('_notes');
    expect($payload)->toHaveKey('data');
    expect($payload['data'])->toHaveKey('images');
    expect($payload['data']['images'])->toHaveCount(1);
    expect($payload['data']['images'][0]['filename'])->toEndWith('.png');

    $asset = Asset::find()->id($payload['data']['images'][0]['id'])->status(null)->one();
    expect($asset)->not->toBeNull();
    expect($asset->title)->toBe('a friendly cat');
});

it('returns the agent a clean images array with id, url, filename, mimeType, width, and height', function () {
    $cap = gptImageRegistry(new Response(200, [], json_encode([
        'data' => [['b64_json' => base64_encode($this->pngBytes)]],
    ], JSON_THROW_ON_ERROR)));

    $output = $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'cat',
        'volume' => 'uploads',
    ]);

    /** @var array{_notes: string, data: array{images: list<array<string, mixed>>}} $payload */
    $payload = json_decode($output->text, true);
    expect($payload['data']['images'][0])->toHaveKeys(['id', 'url', 'filename', 'mimeType', 'width', 'height']);
    expect($payload['data']['images'][0]['mimeType'])->toBe('image/png');
    // Width/height come from getimagesize() on the saved bytes — our 1x1
    // PNG fixture means both should be 1.
    expect($payload['data']['images'][0]['width'])->toBe(1);
    expect($payload['data']['images'][0]['height'])->toBe(1);
    // No leftover open_preview instruction or PreviewSuggestion wrapper.
    expect($output->text)->not->toContain('open_preview');
});

it('reports moderation errors back to the agent without throwing', function () {
    $cap = gptImageRegistry(new Response(400, [], json_encode([
        'error' => [
            'message' => 'Your request was rejected as a result of our safety system.',
            'code' => 'moderation_blocked',
        ],
    ], JSON_THROW_ON_ERROR)));

    $output = $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'forbidden',
        'volume' => 'uploads',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('content policy');
});

it('rejects unknown models up front', function () {
    $cap = gptImageRegistry(new Response(200, [], '{}'));

    $output = $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'cat',
        'volume' => 'uploads',
        'model' => 'midjourney',
    ]);

    expect($output->isError)->toBeTrue();
    expect($cap['history'])->toBe([]);
});

it('rejects unknown background values up front', function () {
    $cap = gptImageRegistry(new Response(200, [], '{}'));

    $output = $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'cat',
        'volume' => 'uploads',
        'background' => 'rainbow',
    ]);

    expect($output->isError)->toBeTrue();
    expect($cap['history'])->toBe([]);
});

it('requires the volume parameter', function () {
    $cap = gptImageRegistry(new Response(200, [], '{}'));

    $output = $cap['registry']->execute('generate_image_gpt_image', [
        'prompt' => 'cat',
    ]);

    expect($output->isError)->toBeTrue();
    expect($cap['history'])->toBe([]);
});
