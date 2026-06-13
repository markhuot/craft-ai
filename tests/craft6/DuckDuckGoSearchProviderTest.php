<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\DuckDuckGoSearchProvider;
use markhuot\craftai\agent\providers\SearchException;

class DuckDuckGoSearchCapture
{
    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    public DuckDuckGoSearchProvider $provider;

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

        $this->provider = new DuckDuckGoSearchProvider(http: $client);
    }
}

function ddgHtml(string $resultsHtml): string
{
    // Wrap in the surrounding page chrome DDG emits so the parser sees
    // realistic structure (the `body > div.serp` container etc.).
    return <<<HTML
<!DOCTYPE html>
<html><head><title>DDG</title></head><body>
<div class="serp">
{$resultsHtml}
</div>
</body></html>
HTML;
}

function ddgResult(string $href, string $title, string $snippet): string
{
    // Mirror the actual markup html.duckduckgo.com returns: title anchor with
    // class `result__a`, snippet anchor/div with class `result__snippet`.
    return <<<HTML
<div class="result results_links results_links_deep web-result">
  <div class="result__body">
    <h2 class="result__title"><a class="result__a" href="{$href}">{$title}</a></h2>
    <a class="result__snippet" href="{$href}">{$snippet}</a>
  </div>
</div>
HTML;
}

it('decodes the uddg redirect param into the real destination URL', function () {
    $target = 'https://webaim.org/techniques/alttext/';
    $href = '//duckduckgo.com/l/?uddg='.rawurlencode($target).'&rut=abc123';
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml(ddgResult($href, 'Alt Text', 'WebAIM guide'))));

    $results = $cap->provider->search('alt text');

    expect($results)->toHaveCount(1);
    expect($results[0]->url)->toBe($target);
    expect($results[0]->title)->toBe('Alt Text');
    expect($results[0]->snippet)->toBe('WebAIM guide');
});

it('passes through direct https URLs without a redirect wrapper', function () {
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml(
        ddgResult('https://example.com/direct', 'Direct hit', 'no redirect'),
    )));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->url)->toBe('https://example.com/direct');
});

it('drops a result whose redirect target is missing', function () {
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml(
        ddgResult('//duckduckgo.com/l/?rut=abc', 'Bad', 'no uddg').
        ddgResult('https://example.com/ok', 'Good', 'real'),
    )));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->url)->toBe('https://example.com/ok');
});

it('honors the limit argument', function () {
    $html = '';
    for ($i = 1; $i <= 8; $i++) {
        $html .= ddgResult("https://example.com/r{$i}", "R{$i}", "s{$i}");
    }
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml($html)));

    $results = $cap->provider->search('q', limit: 3);

    expect($results)->toHaveCount(3);
    expect($results[2]->url)->toBe('https://example.com/r3');
});

it('returns an empty list when DDG yields no result divs', function () {
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml('<p>no matches</p>')));

    expect($cap->provider->search('q'))->toBe([]);
});

it('POSTs the query as form data to /html/', function () {
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml(
        ddgResult('https://example.com/x', 'x', 'x'),
    )));

    $cap->provider->search('alt text accessibility');

    $request = $cap->history[0]['request'];
    expect($request->getMethod())->toBe('POST');
    expect((string) $request->getUri())->toContain('html/');
    parse_str((string) $request->getBody(), $form);
    expect($form['q'])->toBe('alt text accessibility');
});

it('preserves UTF-8 characters in titles and snippets', function () {
    // Bare é (0xC3 0xA9) in the input; the parser's UTF-8 hint should keep it.
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml(
        ddgResult('https://example.com/é', 'Café résumé', 'naïve façade'),
    )));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->title)->toBe('Café résumé');
    expect($results[0]->snippet)->toBe('naïve façade');
});

it('throws SearchException on HTTP error', function () {
    $cap = new DuckDuckGoSearchCapture(new Response(429, [], 'rate limited'));

    try {
        $cap->provider->search('q');
        expect(false)->toBeTrue('should have thrown');
    } catch (SearchException $e) {
        expect($e->getMessage())->toContain('429');
    }
});

it('exposes its name as `duckduckgo` for the registry to route on', function () {
    $cap = new DuckDuckGoSearchCapture(new Response(200, [], ddgHtml('')));

    expect($cap->provider->name())->toBe('duckduckgo');
});
