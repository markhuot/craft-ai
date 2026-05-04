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
});
