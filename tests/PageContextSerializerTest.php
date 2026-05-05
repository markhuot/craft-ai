<?php

use markhuot\craftai\agent\PageContextSerializer;

it('produces the same fingerprint regardless of key order', function () {
    $a = [
        'url' => 'https://example.com/about',
        'path' => 'about',
        'siteHandle' => 'default',
        'template' => '_layouts/page.twig',
        'query' => ['tab' => 'team', 'sort' => 'recent'],
        'element' => ['type' => 'entry', 'id' => 7, 'title' => 'About', 'sectionHandle' => 'pages'],
    ];
    $b = [
        'element' => ['title' => 'About', 'sectionHandle' => 'pages', 'type' => 'entry', 'id' => 7],
        'query' => ['sort' => 'recent', 'tab' => 'team'],
        'template' => '_layouts/page.twig',
        'siteHandle' => 'default',
        'path' => 'about',
        'url' => 'https://example.com/about',
    ];

    expect(PageContextSerializer::fingerprint($a))->toBe(PageContextSerializer::fingerprint($b));
});

it('produces different fingerprints when any field changes', function () {
    $base = [
        'url' => 'https://example.com/about',
        'path' => 'about',
        'siteHandle' => 'default',
        'template' => '_layouts/page.twig',
        'query' => [],
        'element' => null,
    ];
    $diffUrl = [...$base, 'url' => 'https://example.com/contact'];
    $diffElement = [...$base, 'element' => ['type' => 'entry', 'id' => 7, 'title' => null, 'sectionHandle' => null]];

    expect(PageContextSerializer::fingerprint($base))
        ->not->toBe(PageContextSerializer::fingerprint($diffUrl));
    expect(PageContextSerializer::fingerprint($base))
        ->not->toBe(PageContextSerializer::fingerprint($diffElement));
});

it('renders a system note that mentions URL, site, template, and element', function () {
    $note = PageContextSerializer::toSystemNote([
        'url' => 'https://example.com/about?tab=team',
        'path' => 'about',
        'siteHandle' => 'default',
        'template' => '_layouts/page.twig',
        'query' => ['tab' => 'team'],
        'element' => [
            'type' => 'entry',
            'id' => 42,
            'title' => 'About Us',
            'sectionHandle' => 'pages',
        ],
    ]);

    expect($note)->toContain('<page-context>');
    expect($note)->toContain('URL: https://example.com/about?tab=team');
    expect($note)->toContain('Site: default');
    expect($note)->toContain('Template: _layouts/page.twig');
    expect($note)->toContain('Query: tab=team');
    expect($note)->toContain('Element: entry #42 "About Us" (section: pages)');
    expect($note)->toContain('</page-context>');
});

it('marks the element as missing when the URL did not match one', function () {
    $note = PageContextSerializer::toSystemNote([
        'url' => 'https://example.com/raw-route',
        'path' => 'raw-route',
        'siteHandle' => 'default',
        'template' => null,
        'query' => [],
        'element' => null,
    ]);

    expect($note)->toContain('Element: (no element matched this URL)');
});

it('falls back to the path when no absolute URL was captured', function () {
    $note = PageContextSerializer::toSystemNote([
        'url' => null,
        'path' => 'about',
        'siteHandle' => null,
        'template' => null,
        'query' => [],
        'element' => null,
    ]);

    expect($note)->toContain('Path: about');
    expect($note)->not->toContain('URL:');
});
