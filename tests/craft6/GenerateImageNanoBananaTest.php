<?php

use craft\elements\Asset;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\GeminiImageProvider;
use markhuot\craftai\tools\GenerateImageNanoBanana;
use markhuot\craftai\tools\ToolRegistry;

if (! function_exists('seedImageVolume')) {
    /**
     * Create a Local filesystem backed by a real temp directory and a Volume
     * that references it by handle, then refresh Craft's volume/fs caches so
     * the legacy services (Craft::$app->volumes / ->fs) the tools call resolve
     * a writable volume. The native Volume::factory() only writes
     * `fs => Local::class` and never registers an actual filesystem, so assets
     * cannot be written to it ("Volume is missing or has an invalid filesystem
     * handle.").
     */
    function seedImageVolume(string $handle, string $name): \CraftCms\Cms\Asset\Models\Volume
    {
        $path = sys_get_temp_dir().'/craftai-vol-'.$handle.'-'.bin2hex(random_bytes(4));
        \craft\helpers\FileHelper::createDirectory($path);

        $fs = new \CraftCms\Cms\Filesystem\Filesystems\Local();
        $fs->name = $name;
        $fs->handle = $handle;
        $fs->path = $path;
        $fs->hasUrls = false;
        \CraftCms\Cms\Support\Facades\Filesystems::saveFilesystem($fs);

        $volume = \CraftCms\Cms\Asset\Models\Volume::factory()->create([
            'name' => $name,
            'handle' => $handle,
            'fs' => $handle,
        ]);

        // Drop the volume service's memoized snapshot so the next handle
        // lookup re-reads from the DB and sees this (and any sibling) volume.
        \CraftCms\Cms\Support\Facades\Volumes::reset();

        return $volume;
    }
}

beforeEach(function () {
    seedImageVolume('uploads', 'Uploads');
    $this->pngBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII='
    );
});

/**
 * @return array{registry: ToolRegistry, history: array<int, array<string, mixed>>}
 */
function nanoBananaRegistry(Response $response): array
{
    /** @var array<int, array<string, mixed>> $history */
    $history = [];
    $stack = HandlerStack::create(new MockHandler([$response]));
    $stack->push(Middleware::history($history));
    $client = new Client(['handler' => $stack]);
    Craft::$container->set(
        GeminiImageProvider::class,
        new GeminiImageProvider('test-key', 'gemini-2.5-flash-image', $client),
    );

    $registry = new ToolRegistry();
    $registry->register(GenerateImageNanoBanana::class);

    return ['registry' => $registry, 'history' => &$history];
}

function nbBody(string $b64): Response
{
    return new Response(200, [], json_encode([
        'candidates' => [[
            'content' => ['parts' => [
                ['text' => 'here you go'],
                ['inlineData' => ['mimeType' => 'image/png', 'data' => $b64]],
            ]],
            'finishReason' => 'STOP',
        ]],
    ], JSON_THROW_ON_ERROR));
}

it('builds a Gemini-shaped request matching the REST docs (contents + imageConfig.aspectRatio)', function () {
    $cap = nanoBananaRegistry(nbBody(base64_encode($this->pngBytes)));

    $output = $cap['registry']->execute('generate_image_nano_banana', [
        'prompt' => 'a friendly cat',
        'volume' => 'uploads',
        'aspectRatio' => '16:9',
    ]);

    expect($output->isError)->toBeFalse($output->text);

    $sent = json_decode((string) $cap['history'][0]['request']->getBody(), true);
    expect($sent['contents'][0]['parts'][0]['text'])->toBe('a friendly cat');
    expect($sent['generationConfig']['imageConfig']['aspectRatio'])->toBe('16:9');
    // The docs example does not set responseModalities — default returns
    // both text and image, which the parser handles. We omit it to match.
    expect($sent['generationConfig'] ?? [])->not->toHaveKey('responseModalities');
});

it('omits generationConfig entirely when no aspectRatio is given so Gemini picks defaults', function () {
    $cap = nanoBananaRegistry(nbBody(base64_encode($this->pngBytes)));

    $cap['registry']->execute('generate_image_nano_banana', [
        'prompt' => 'cat',
        'volume' => 'uploads',
    ]);

    $sent = json_decode((string) $cap['history'][0]['request']->getBody(), true);
    expect($sent)->not->toHaveKey('generationConfig');
});

it('saves the returned bytes as a Craft asset and reports the images array', function () {
    $cap = nanoBananaRegistry(nbBody(base64_encode($this->pngBytes)));

    $output = $cap['registry']->execute('generate_image_nano_banana', [
        'prompt' => 'a friendly cat',
        'volume' => 'uploads',
    ]);

    /** @var array{_notes: string, data: array{images: list<array{id: int, url: ?string, filename: string}>}} $payload */
    $payload = json_decode($output->text, true);
    expect($payload)->toHaveKey('_notes');
    expect($payload)->toHaveKey('data');
    expect($payload['data'])->toHaveKey('images');
    expect($payload['data']['images'])->toHaveCount(1);
    expect($payload['data']['images'][0]['filename'])->toEndWith('.png');
    // No legacy open_preview/PreviewSuggestion wrapper — the front-end shows
    // the image inline from the tool_result block, no agent instruction needed.
    expect($output->text)->not->toContain('open_preview');

    $asset = Asset::find()->id($payload['data']['images'][0]['id'])->status(null)->one();
    expect($asset)->not->toBeNull();
    expect($asset->title)->toBe('a friendly cat');
});

it('reports a SAFETY block back to the agent without throwing', function () {
    $cap = nanoBananaRegistry(new Response(200, [], json_encode([
        'candidates' => [['finishReason' => 'SAFETY']],
    ], JSON_THROW_ON_ERROR)));

    $output = $cap['registry']->execute('generate_image_nano_banana', [
        'prompt' => 'forbidden',
        'volume' => 'uploads',
    ]);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('content policy');
});

it('rejects unknown aspect ratios up front', function () {
    $cap = nanoBananaRegistry(nbBody(base64_encode($this->pngBytes)));

    $output = $cap['registry']->execute('generate_image_nano_banana', [
        'prompt' => 'cat',
        'volume' => 'uploads',
        'aspectRatio' => 'cinemascope',
    ]);

    expect($output->isError)->toBeTrue();
    expect($cap['history'])->toBe([]);
});
