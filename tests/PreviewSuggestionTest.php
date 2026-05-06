<?php

use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\helpers\PreviewSuggestion;

function cpContext(): ToolContext
{
    $ctx = new ToolContext();
    $ctx->begin('test-session', 'test-tool-use', ClientType::CP);

    return $ctx;
}

function mcpContext(): ToolContext
{
    $ctx = new ToolContext();
    $ctx->begin(null, null, ClientType::MCP);

    return $ctx;
}

function widgetContext(): ToolContext
{
    $ctx = new ToolContext();
    $ctx->begin('widget-session', 'widget-tu', ClientType::WIDGET);

    return $ctx;
}

it('wraps with an open_preview prompt on the CP surface when a URL is available', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 5, 'title' => 'Hi'],
        'https://example.test/hi',
        'entry',
        cpContext(),
    );

    expect($wrapped)->toHaveKeys(['notes', 'entry']);
    expect($wrapped['notes'])->toContain('open_preview');
    expect($wrapped['notes'])->toContain('https://example.test/hi');
    expect($wrapped['entry'])->toBe(['id' => 5, 'title' => 'Hi']);
});

it('uses the provided key as the noun in the notes prompt', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 1],
        'https://example.test/d',
        'draft',
        cpContext(),
    );

    expect($wrapped['notes'])->toStartWith('Draft saved');
});

it('returns the payload unchanged on the CP surface when no URL is available', function () {
    expect(PreviewSuggestion::wrap(['id' => 9], null, 'entry', cpContext()))
        ->toBe(['id' => 9]);
    expect(PreviewSuggestion::wrap(['id' => 9], '', 'entry', cpContext()))
        ->toBe(['id' => 9]);
});

it('places notes before the payload key so the agent sees the instruction first', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 1],
        'https://example.test/x',
        'asset',
        cpContext(),
    );

    expect(array_keys($wrapped))->toBe(['notes', 'asset']);
});

it('emits a generic note for MCP that does not reference open_preview', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 5, 'title' => 'Hi'],
        'https://example.test/hi',
        'entry',
        mcpContext(),
    );

    expect($wrapped)->toHaveKeys(['notes', 'entry']);
    expect($wrapped['notes'])->toBe('Entry saved.');
    expect($wrapped['notes'])->not->toContain('open_preview');
});

it('still wraps for MCP even when no URL is available', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 9, 'title' => 'No URL'],
        null,
        'entry',
        mcpContext(),
    );

    expect($wrapped)->toHaveKeys(['notes', 'entry']);
    expect($wrapped['notes'])->toBe('Entry saved.');
});

it('emits a generic note for the front-end widget surface', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 1],
        'https://example.test/x',
        'entry',
        widgetContext(),
    );

    expect($wrapped['notes'])->toBe('Entry saved.');
    expect($wrapped['notes'])->not->toContain('open_preview');
});

it('treats an unset client like a non-CP surface (conservative default)', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 1],
        'https://example.test/x',
        'entry',
        new ToolContext(),
    );

    expect($wrapped['notes'])->toBe('Entry saved.');
    expect($wrapped['notes'])->not->toContain('open_preview');
});
