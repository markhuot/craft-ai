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

    expect($output->isError)->toBeFalse();
    expect($output->text)->toContain('Hello');
    expect($output->text)->toContain('World & friends');
    expect($output->text)->not->toContain('alert(1)');
    expect($output->text)->not->toContain('<h1>');
});

it('returns full HTML when fullHtml is true', function () {
    $html = '<html><body><p>Hi</p></body></html>';
    $tool = fetchWebpageWithResponse(new Response(200, [], $html));

    $output = $tool('https://example.com', fullHtml: true);

    expect($output->text)->toBe($html);
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
