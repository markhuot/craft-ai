<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\tools\GetImage;

function getImageWithResponse(Response $response): GetImage
{
    $mock = new MockHandler([$response]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    return new GetImage($client);
}

/**
 * 1x1 transparent PNG. The smallest legal payload that still starts with the
 * PNG magic bytes — enough to exercise the success path without fixture files.
 */
function pngFixtureBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
        true,
    ) ?: '';
}

it('rejects non-http URLs', function () {
    $tool = new GetImage();

    $output = $tool('file:///etc/passwd');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('http://');
});

it('returns multimodal blocks for a successful png fetch', function () {
    $bytes = pngFixtureBytes();
    $tool = getImageWithResponse(new Response(200, ['Content-Type' => 'image/png'], $bytes));

    $output = $tool('https://example.com/pixel.png');

    expect($output->isError)->toBeFalse();
    expect($output->blocks)->not->toBeNull();
    expect($output->blocks)->toHaveCount(2);
    expect($output->blocks[0])->toMatchArray(['type' => 'text']);
    expect($output->blocks[0]['text'])->toContain('image/png');
    expect($output->blocks[1]['type'])->toBe('image');
    expect($output->blocks[1]['source'])->toMatchArray([
        'type' => 'base64',
        'media_type' => 'image/png',
    ]);
    expect($output->blocks[1]['source']['data'])->toBe(base64_encode($bytes));
});

it('returns an error for HTTP error responses', function () {
    $tool = getImageWithResponse(new Response(404, [], ''));

    $output = $tool('https://example.com/missing.png');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('404');
});

it('normalizes image/jpg to image/jpeg', function () {
    $bytes = "\xFF\xD8\xFF\xE0".str_repeat('a', 16);
    $tool = getImageWithResponse(new Response(200, ['Content-Type' => 'image/jpg'], $bytes));

    $output = $tool('https://example.com/p.jpg');

    expect($output->isError)->toBeFalse();
    expect($output->blocks[1]['source']['media_type'])->toBe('image/jpeg');
});

it('sniffs the media type when the server returns octet-stream', function () {
    $bytes = pngFixtureBytes();
    $tool = getImageWithResponse(new Response(200, ['Content-Type' => 'application/octet-stream'], $bytes));

    $output = $tool('https://example.com/pixel');

    expect($output->isError)->toBeFalse();
    expect($output->blocks[1]['source']['media_type'])->toBe('image/png');
});

it('rejects responses that are not a supported image type', function () {
    $tool = getImageWithResponse(new Response(200, ['Content-Type' => 'text/html'], '<html></html>'));

    $output = $tool('https://example.com/page');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('Supported types');
});

it('rejects images larger than the configured cap', function () {
    $oversize = str_repeat('A', 5 * 1024 * 1024 + 1);
    $tool = getImageWithResponse(new Response(200, ['Content-Type' => 'image/png'], $oversize));

    $output = $tool('https://example.com/big.png');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('exceeds');
});
