<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\tools\FetchWebpage;

function fetchWebpageWithResponse(Response $response): FetchWebpage
{
    $mock = new MockHandler([$response]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);

    return new FetchWebpage($client);
}

it('returns plain text by default, stripping tags and scripts', function () {
    $html = '<html><head><style>body{}</style></head><body><h1>Hello</h1><script>alert(1)</script><p>World &amp; friends</p></body></html>';
    $tool = fetchWebpageWithResponse(new Response(200, [], $html));

    $output = $tool('https://example.com');

    expect($output)->toBeArray();
    expect($output['_notes'])->toContain('Fetched');
    $content = $output['data']['content'];
    expect($content)->toContain('Hello');
    expect($content)->toContain('World & friends');
    expect($content)->not->toContain('alert(1)');
    expect($content)->not->toContain('<h1>');
    expect($output['data']['format'])->toBe('text');
    expect($output['data']['status'])->toBe(200);
});

it('returns full HTML when fullHtml is true', function () {
    $html = '<html><body><p>Hi</p></body></html>';
    $tool = fetchWebpageWithResponse(new Response(200, [], $html));

    $output = $tool('https://example.com', fullHtml: true);

    expect($output)->toBeArray();
    expect($output['data']['content'])->toBe($html);
    expect($output['data']['format'])->toBe('html');
});

it('rejects non-http URLs', function () {
    $tool = new FetchWebpage();

    $output = $tool('file:///etc/passwd');

    expect($output->isError)->toBeTrue();
});

it('returns an error for HTTP error responses', function () {
    $tool = fetchWebpageWithResponse(new Response(404, [], 'not found'));

    $output = $tool('https://example.com/missing');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('404');
    expect($output->text)->toContain('not found');
});

it('includes the response body text in HTTP error output, stripping styles', function () {
    $body = '<html><head><style>body{font:12px sans-serif}</style></head><body><h1>Twig\\Error\\SyntaxError</h1><p>Regexp "/^[\\u2022]/" is not valid at offset 6</p></body></html>';
    $tool = fetchWebpageWithResponse(new Response(500, [], $body));

    $output = $tool('https://example.com/boom');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('500');
    expect($output->text)->toContain('Twig\\Error\\SyntaxError');
    expect($output->text)->toContain('not valid at offset 6');
    expect($output->text)->not->toContain('<style>');
    expect($output->text)->not->toContain('font:12px');
});

it('returns raw HTML in error output when fullHtml is true', function () {
    $body = '<html><body><p>boom</p></body></html>';
    $tool = fetchWebpageWithResponse(new Response(500, [], $body));

    $output = $tool('https://example.com/boom', fullHtml: true);

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('500');
    expect($output->text)->toContain($body);
});

it('truncates very long error responses', function () {
    $longBody = str_repeat('A', 20000);
    $tool = fetchWebpageWithResponse(new Response(500, [], $longBody));

    $output = $tool('https://example.com/boom');

    expect($output->isError)->toBeTrue();
    expect($output->text)->toContain('[truncated]');
    expect(strlen($output->text))->toBeLessThan(9000);
});

it('decodes pages declared as ISO-8859-1 in the Content-Type header', function () {
    // £ is 0xA3 in Latin-1 / Windows-1252 — two-byte 0xC2 0xA3 in UTF-8.
    $body = "<html><body><p>Price: \xA3100</p></body></html>";
    $tool = fetchWebpageWithResponse(new Response(
        200,
        ['Content-Type' => 'text/html; charset=ISO-8859-1'],
        $body,
    ));

    $output = $tool('https://example.com');

    expect($output)->toBeArray();
    $content = $output['data']['content'];
    expect($content)->toContain('£100');
    expect(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
});

it('decodes pages declared as Windows-1252 via a meta charset tag', function () {
    // 0x93 is a left double-quote in Windows-1252, an unassigned control in
    // strict ISO-8859-1. Browsers (and we) treat Latin-1 as Windows-1252.
    $body = "<html><head><meta charset=\"iso-8859-1\"></head><body><p>\x93Hi\x94</p></body></html>";
    $tool = fetchWebpageWithResponse(new Response(200, [], $body));

    $output = $tool('https://example.com');

    expect($output)->toBeArray();
    $content = $output['data']['content'];
    expect(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
    expect($content)->toContain('“Hi”');
});

it('scrubs stray invalid UTF-8 bytes so json_encode never fails', function () {
    // A lone continuation byte (0x80) in the middle of otherwise-ASCII text
    // is invalid UTF-8 and would crash json_encode(JSON_THROW_ON_ERROR).
    $body = "<html><body><p>Hello \x80 World</p></body></html>";
    $tool = fetchWebpageWithResponse(new Response(
        200,
        ['Content-Type' => 'text/html; charset=UTF-8'],
        $body,
    ));

    $output = $tool('https://example.com');

    expect($output)->toBeArray();
    $content = $output['data']['content'];
    expect(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
    expect(fn () => json_encode($output, JSON_THROW_ON_ERROR))->not->toThrow(\JsonException::class);
});

it('truncates without splitting a multibyte UTF-8 character', function () {
    // Pad to push the multibyte char (€ = 0xE2 0x82 0xAC) across the 8000-byte
    // truncation boundary. With byte-based substr this would yield a dangling
    // 0xE2, producing invalid UTF-8 in the resulting tool output.
    $longBody = str_repeat('A', 7999).'€'.str_repeat('A', 5000);
    $tool = fetchWebpageWithResponse(new Response(500, [], $longBody));

    $output = $tool('https://example.com/boom');

    expect($output->isError)->toBeTrue();
    expect(mb_check_encoding($output->text, 'UTF-8'))->toBeTrue();
    expect($output->text)->toContain('[truncated]');
});
