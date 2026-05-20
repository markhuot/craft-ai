<?php

use markhuot\craftai\helpers\CommentMarkerCleanup;

it('strips a single span carrying the matching referenceId', function () {
    $html = '<p>Before <span class="craft-ai-comment-mark" data-craft-ai-comment-id="abc-123">flagged text</span> after.</p>';
    $result = CommentMarkerCleanup::stripMarker($html, 'abc-123');
    expect($result)->toContain('flagged text');
    expect($result)->not->toContain('data-craft-ai-comment-id');
    expect($result)->not->toContain('craft-ai-comment-mark');
});

it('leaves the HTML untouched when no marker matches', function () {
    $html = '<p>Plain paragraph with no markers at all.</p>';
    $result = CommentMarkerCleanup::stripMarker($html, 'never-going-to-match');
    expect($result)->toBe($html);
});

it('strips only the marker whose referenceId matches', function () {
    $html = '<p>'
        .'<span class="craft-ai-comment-mark" data-craft-ai-comment-id="keep-me">stay</span>'
        .' and '
        .'<span class="craft-ai-comment-mark" data-craft-ai-comment-id="drop-me">go</span>'
        .'</p>';
    $result = CommentMarkerCleanup::stripMarker($html, 'drop-me');
    expect($result)->toContain('data-craft-ai-comment-id="keep-me"');
    expect($result)->not->toContain('data-craft-ai-comment-id="drop-me"');
    expect($result)->toContain('stay');
    expect($result)->toContain('go');
});

it('preserves nested inline content when unwrapping the marker', function () {
    $html = '<p>'
        .'<span class="craft-ai-comment-mark" data-craft-ai-comment-id="abc">'
        .'Hello <a href="https://example.com">link</a> <strong>bold</strong>'
        .'</span>'
        .'</p>';
    $result = CommentMarkerCleanup::stripMarker($html, 'abc');
    expect($result)->not->toContain('craft-ai-comment-mark');
    expect($result)->toContain('<a href="https://example.com">link</a>');
    expect($result)->toContain('<strong>bold</strong>');
    expect($result)->toContain('Hello');
});

it('short-circuits when the marker id is not in the HTML at all', function () {
    // Cheap pre-check should skip the DOMDocument round-trip entirely
    // for fields whose HTML doesn't contain the referenceId substring.
    // We verify behaviorally (the result is bit-identical to the
    // input) rather than asserting the DOM wasn't loaded — the
    // important contract is "same string out for unrelated HTML."
    $html = '<p>Lots of <span class="other">other</span> spans, none ours.</p>';
    $result = CommentMarkerCleanup::stripMarker($html, 'abc-xyz');
    expect($result)->toBe($html);
});

it('handles markers across multiple paragraphs', function () {
    $html = '<p>First <span data-craft-ai-comment-id="ref">part of</span> sentence.</p>'
        .'<p>Second <span data-craft-ai-comment-id="ref">part of</span> sentence.</p>';
    $result = CommentMarkerCleanup::stripMarker($html, 'ref');
    expect($result)->not->toContain('data-craft-ai-comment-id');
    expect(substr_count($result, 'part of'))->toBe(2);
});
