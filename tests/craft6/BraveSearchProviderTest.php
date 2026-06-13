<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\BraveSearchProvider;
use markhuot\craftai\agent\providers\SearchException;

class BraveSearchCapture
{
    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    public BraveSearchProvider $provider;

    /**
     * @param  Response|list<Response>  $responses
     */
    public function __construct(Response|array $responses)
    {
        $queue = is_array($responses) ? $responses : [$responses];
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://search.brave.com/',
        ]);

        $this->provider = new BraveSearchProvider(http: $client);
    }
}

/**
 * Build a Brave-shaped result block. Mirrors the structure observed on a
 * real search.brave.com response: `data-type="web"` + `data-pos="N"` on
 * the outer div, an anchor with the result URL, a `div.title` with a
 * `title` attribute, and a `div.generic-snippet > div.content` for the
 * snippet. Svelte hash suffixes are included so the parser proves it can
 * ignore them.
 */
function braveResult(int $pos, string $url, string $title, string $snippet, string $dateHint = ''): string
{
    $dateSpan = $dateHint !== '' ? "<span class=\"t-secondary\">{$dateHint}</span> " : '';

    return <<<HTML
<div class="snippet  svelte-jmfu5f" data-pos="{$pos}" data-type="web" data-keynav="true">
  <div class="result-wrapper svelte-1rq4ngz">
    <div class="result-content svelte-1rq4ngz">
      <a href="{$url}" target="_self" class="svelte-14r20fy l1">
        <div class="site-name-wrapper svelte-on1hvy">
          <cite class="snippet-url desktop-small-regular t-tertiary svelte-on1hvy">example.com</cite>
        </div>
        <div class="title search-snippet-title line-clamp-1 svelte-14r20fy" title="{$title}">{$title}</div>
      </a>
      <div class="generic-snippet svelte-1cwdgg3">
        <div class="content desktop-default-regular t-primary line-clamp-dynamic svelte-1cwdgg3">{$dateSpan}{$snippet}</div>
      </div>
    </div>
  </div>
</div>
HTML;
}

function bravePage(string $resultsHtml): string
{
    return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><title>Brave Search</title></head>
<body>
<div id="results">
{$resultsHtml}
</div>
</body></html>
HTML;
}

it('hits /search with q and source=web', function () {
    $cap = new BraveSearchCapture(new Response(200, [], bravePage(
        braveResult(0, 'https://example.com/a', 'A', 'snippet A'),
    )));

    $cap->provider->search('met gala');

    $request = $cap->history[0]['request'];
    expect($request->getUri()->getPath())->toBe('/search');
    parse_str((string) $request->getUri()->getQuery(), $params);
    expect($params['q'])->toBe('met gala');
    expect($params['source'])->toBe('web');
    expect($request->getHeaderLine('User-Agent'))->toContain('Mozilla/5.0');
});

it('parses organic web results into SearchResult objects', function () {
    $html = braveResult(0, 'https://www.vogue.com/tag/event/met-gala', 'Met Gala 2026: Celebrities, Red Carpet, Theme & More | Vogue', 'Affectionately referred to as fashion\'s biggest night out.').
        braveResult(1, 'https://en.wikipedia.org/wiki/Met_Gala', 'Met Gala - Wikipedia', 'The Met Gala is a yearly fundraising gala held for the benefit.');
    $cap = new BraveSearchCapture(new Response(200, [], bravePage($html)));

    $results = $cap->provider->search('met gala');

    expect($results)->toHaveCount(2);
    expect($results[0]->url)->toBe('https://www.vogue.com/tag/event/met-gala');
    expect($results[0]->title)->toContain('Met Gala 2026');
    expect($results[0]->snippet)->toContain('biggest night out');
    expect($results[1]->url)->toBe('https://en.wikipedia.org/wiki/Met_Gala');
});

it('skips ads and clusters (non-web data-types)', function () {
    $ad = '<div class="snippet" data-pos="0" data-type="ad"><a href="https://ad.example.com">ad</a><div class="title" title="Ad">Ad</div><div class="generic-snippet"><div class="content">sponsored snippet</div></div></div>';
    $cluster = '<div class="snippet" data-pos="0" data-type="cluster"><a href="https://news.example.com">cluster</a><div class="title" title="C">C</div><div class="generic-snippet"><div class="content">news cluster snippet</div></div></div>';
    $real = braveResult(0, 'https://example.com/real', 'Real result', 'real snippet long enough');

    $cap = new BraveSearchCapture(new Response(200, [], bravePage($ad.$cluster.$real)));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->url)->toBe('https://example.com/real');
});

it('prefers the title attribute over the truncated visible text', function () {
    // Brave's display title is truncated via line-clamp; the `title` attr
    // carries the full version.
    $block = <<<HTML
<div class="snippet" data-pos="0" data-type="web">
  <a href="https://example.com/full">
    <div class="title" title="Full untruncated title here">Full untruncated…</div>
  </a>
  <div class="generic-snippet"><div class="content">snippet body</div></div>
</div>
HTML;
    $cap = new BraveSearchCapture(new Response(200, [], bravePage($block)));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->title)->toBe('Full untruncated title here');
});

it('includes the relative-date prefix in snippets when Brave provides one', function () {
    $cap = new BraveSearchCapture(new Response(200, [], bravePage(
        braveResult(0, 'https://example.com/a', 'A', 'fashion\'s biggest night out.', dateHint: '18 hours ago -'),
    )));

    $results = $cap->provider->search('met gala');

    expect($results[0]->snippet)->toContain('18 hours ago');
    expect($results[0]->snippet)->toContain('biggest night out');
});

it('ignores rotating svelte-XXX class suffixes', function () {
    // Same structure, different svelte hash suffixes — the parser should be
    // anchored on data-type, not the rotating CSS hashes.
    $block = '<div class="snippet  svelte-abc123" data-pos="0" data-type="web">'.
        '<a href="https://example.com/x" class="svelte-zzz999 l1">'.
        '<div class="title svelte-qqq111" title="X">X</div>'.
        '</a>'.
        '<div class="generic-snippet svelte-mmm222"><div class="content svelte-nnn333">snippet text body</div></div>'.
        '</div>';
    $cap = new BraveSearchCapture(new Response(200, [], bravePage($block)));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->url)->toBe('https://example.com/x');
    expect($results[0]->title)->toBe('X');
});

it('skips brave.com favicon / chrome anchors when picking the result URL', function () {
    $block = '<div class="snippet" data-pos="0" data-type="web">'.
        '<a href="https://imgs.search.brave.com/favicon.png"><img alt="🌐"/></a>'.
        '<a href="https://realtarget.example.com/page">'.
        '<div class="title" title="Real target">Real target</div>'.
        '</a>'.
        '<div class="generic-snippet"><div class="content">snippet</div></div>'.
        '</div>';
    $cap = new BraveSearchCapture(new Response(200, [], bravePage($block)));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->url)->toBe('https://realtarget.example.com/page');
});

it('deduplicates repeated URLs', function () {
    $html = braveResult(0, 'https://example.com/a', 'A1', 'first snippet').
        braveResult(1, 'https://example.com/a', 'A2', 'second snippet').
        braveResult(2, 'https://example.com/b', 'B', 'B snippet');
    $cap = new BraveSearchCapture(new Response(200, [], bravePage($html)));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(2);
    expect($results[0]->url)->toBe('https://example.com/a');
    expect($results[1]->url)->toBe('https://example.com/b');
});

it('honors the limit argument', function () {
    $html = '';
    for ($i = 0; $i < 8; $i++) {
        $html .= braveResult($i, "https://example.com/r{$i}", "R{$i}", "snippet {$i}");
    }
    $cap = new BraveSearchCapture(new Response(200, [], bravePage($html)));

    $results = $cap->provider->search('q', limit: 3);

    expect($results)->toHaveCount(3);
});

it('returns an empty list when there are no web results', function () {
    $cap = new BraveSearchCapture(new Response(200, [], bravePage('<p>nothing here</p>')));

    expect($cap->provider->search('q'))->toBe([]);
});

it('maps 429 / 503 HTTP errors to a rate-limit SearchException', function () {
    $cap = new BraveSearchCapture(new Response(429, [], 'too many requests'));

    try {
        $cap->provider->search('q');
        expect(false)->toBeTrue('should have thrown');
    } catch (SearchException $e) {
        expect($e->getMessage())->toContain('429');
        expect($e->getMessage())->toContain('rate limited');
    }
});

it('preserves UTF-8 characters in titles and snippets', function () {
    $cap = new BraveSearchCapture(new Response(200, [], bravePage(
        braveResult(0, 'https://example.com/é', 'Café résumé', 'naïve façade explanation'),
    )));

    $results = $cap->provider->search('q');

    expect($results)->toHaveCount(1);
    expect($results[0]->title)->toBe('Café résumé');
    expect($results[0]->snippet)->toContain('naïve façade');
});

it('exposes its name as `brave` for the registry to route on', function () {
    $cap = new BraveSearchCapture(new Response(200, [], bravePage('')));

    expect($cap->provider->name())->toBe('brave');
});
