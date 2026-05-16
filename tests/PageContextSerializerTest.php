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

it('renders a code-component framing when the context carries a fieldAuthor block', function () {
    $note = PageContextSerializer::toSystemNote([
        'url' => null,
        'path' => null,
        'template' => null,
        'siteHandle' => null,
        'query' => [],
        'element' => [
            'type' => 'entry',
            'id' => 100,
            'title' => 'Pricing',
            'sectionHandle' => 'pages',
        ],
        'fieldAuthor' => [
            'kind' => 'code-component-field',
            'fieldHandle' => 'component',
            'fieldName' => 'Component',
            'fieldId' => 42,
            'currentValues' => [
                'twig' => '<h1>{{ entry.title }}</h1>',
                'css' => 'h1 { color: red; }',
                'js' => '',
            ],
        ],
    ]);

    expect($note)->toContain('<code-component-context>');
    expect($note)->toContain('</code-component-context>');
    expect($note)->toContain('update_code_component');
    expect($note)->toContain('Field: "Component" (handle: component, id: 42)');
    expect($note)->toContain('Element: entry #100 "Pricing" (section: pages)');
    expect($note)->toContain("Current Twig:\n```twig\n<h1>{{ entry.title }}</h1>\n```");
    expect($note)->toContain("Current CSS:\n```css\nh1 { color: red; }\n```");
    expect($note)->toContain("Current JS:\n(empty)");
    // Field-author framing replaces — does not stack on top of — the
    // public-site preamble.
    expect($note)->not->toContain('<page-context>');
});

it('marks the element as not-yet-saved when the field is opened on a fresh entry', function () {
    $note = PageContextSerializer::toSystemNote([
        'element' => null,
        'fieldAuthor' => [
            'kind' => 'code-component-field',
            'fieldHandle' => 'component',
            'fieldName' => 'Component',
            'fieldId' => 42,
            'currentValues' => ['twig' => '', 'css' => '', 'js' => ''],
        ],
    ]);

    expect($note)->toContain('Element: (entry not yet saved)');
    // All three tabs should read as (empty), not as blank code fences.
    expect($note)->toContain("Current Twig:\n(empty)");
    expect($note)->toContain("Current CSS:\n(empty)");
    expect($note)->toContain("Current JS:\n(empty)");
});

it('falls back to the unknown-field header when the field handle and name are missing', function () {
    $note = PageContextSerializer::toSystemNote([
        'element' => null,
        'fieldAuthor' => [
            'kind' => 'code-component-field',
            'currentValues' => ['twig' => 'x', 'css' => '', 'js' => ''],
        ],
    ]);

    expect($note)->toContain('Field: (unknown)');
});

it('ignores an unrecognized fieldAuthor kind and falls back to the page-context branch', function () {
    $note = PageContextSerializer::toSystemNote([
        'url' => 'https://example.com/about',
        'path' => 'about',
        'siteHandle' => 'default',
        'template' => null,
        'query' => [],
        'element' => null,
        'fieldAuthor' => [
            'kind' => 'some-future-thing',
            'whatever' => 'data',
        ],
    ]);

    expect($note)->toContain('<page-context>');
    expect($note)->not->toContain('<code-component-context>');
    expect($note)->toContain('URL: https://example.com/about');
});

it('produces different fingerprints when the field-author current values change', function () {
    $base = [
        'element' => null,
        'fieldAuthor' => [
            'kind' => 'code-component-field',
            'fieldHandle' => 'component',
            'fieldName' => 'Component',
            'fieldId' => 42,
            'currentValues' => ['twig' => 'A', 'css' => '', 'js' => ''],
        ],
    ];
    $changed = $base;
    $changed['fieldAuthor']['currentValues']['twig'] = 'B';

    expect(PageContextSerializer::fingerprint($base))
        ->not->toBe(PageContextSerializer::fingerprint($changed));
});
