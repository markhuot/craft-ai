<?php

use markhuot\craftai\helpers\CommentMarkdown;

it('renders bold, italic, and inline code', function () {
    $html = CommentMarkdown::render('This **needs** *fixing*. Use `craft up` first.');

    expect($html)->toContain('<strong>needs</strong>');
    expect($html)->toContain('<em>fixing</em>');
    expect($html)->toContain('<code>craft up</code>');
});

it('renders bullet and ordered lists like GFM', function () {
    $html = CommentMarkdown::render(<<<MD
        - one
        - two
        - three
        MD);

    expect($html)->toContain('<ul>');
    expect($html)->toContain('<li>one</li>');
    expect($html)->toContain('<li>three</li>');
});

it('renders fenced code blocks', function () {
    $html = CommentMarkdown::render(<<<MD
        ```php
        \$entry->title = 'hi';
        ```
        MD);

    expect($html)->toContain('<pre>');
    expect($html)->toContain('<code');
    expect($html)->toContain("\$entry-&gt;title");
});

it('renders links with safe schemes only', function () {
    $html = CommentMarkdown::render('See [docs](https://example.com).');
    expect($html)->toMatch('/<a[^>]+href="https:\/\/example\.com"[^>]*>docs<\/a>/');

    // javascript: URLs must be stripped — the LLM (or a prompt-injected
    // user) shouldn't be able to smuggle them through as a clickable link
    // in the popover. (The literal text "javascript:" surviving as plain
    // body text is harmless; what matters is that no <a href="javascript:..">
    // makes it through.)
    $hostile = CommentMarkdown::render('<a href="javascript:alert(1)">click</a>');
    expect($hostile)->not->toMatch('/href="javascript:/i');
});

it('strips dangerous inline HTML the LLM may emit', function () {
    $html = CommentMarkdown::render('<script>alert(1)</script>\nA point.');
    expect($html)->not->toContain('<script');
    expect($html)->not->toContain('alert(1)');
});

it('strips on-* attributes from purified anchors', function () {
    $html = CommentMarkdown::render('<a href="https://example.com" onclick="alert(1)">x</a>');
    expect($html)->not->toContain('onclick');
});

it('returns an empty string for an empty body so callers can innerHTML safely', function () {
    expect(CommentMarkdown::render(''))->toBe('');
    expect(CommentMarkdown::render('   '))->toBe('');
});
