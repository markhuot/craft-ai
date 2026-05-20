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

it('includes the cpEditUrl link instruction on the CP surface', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 5, 'title' => 'Hi'],
        'https://example.test/hi',
        'entry',
        cpContext(),
        'https://admin.example.test/admin/entries/news/5',
    );

    expect($wrapped['notes'])->toContain('open_preview');
    expect($wrapped['notes'])->toContain('https://example.test/hi');
    expect($wrapped['notes'])->toContain('review and edit');
    expect($wrapped['notes'])->toContain('https://admin.example.test/admin/entries/news/5');
});

it('wraps with just the cpEditUrl when CP has no front-end URL', function () {
    // Sections without URI formats, draft entries on disabled sections,
    // assets on filesystems with no public URLs — all valid CP saves
    // that have no preview pane to drive but DO have an edit screen the
    // editor can be linked back to.
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 9],
        null,
        'entry',
        cpContext(),
        'https://admin.example.test/admin/entries/9',
    );

    expect($wrapped)->toHaveKeys(['notes', 'entry']);
    expect($wrapped['notes'])->not->toContain('open_preview');
    expect($wrapped['notes'])->toContain('https://admin.example.test/admin/entries/9');
});

it('includes the cpEditUrl link instruction on the widget surface', function () {
    // Widget is browser-based — it has no preview pane, but the user
    // can still click through to the CP edit screen to review.
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 11],
        'https://example.test/x',
        'entry',
        widgetContext(),
        'https://admin.example.test/admin/entries/11',
    );

    expect($wrapped['notes'])->not->toContain('open_preview');
    expect($wrapped['notes'])->toContain('review and edit');
    expect($wrapped['notes'])->toContain('https://admin.example.test/admin/entries/11');
});

it('omits the cpEditUrl link instruction on MCP (no browser to click through)', function () {
    $wrapped = PreviewSuggestion::wrap(
        ['id' => 12],
        'https://example.test/x',
        'entry',
        mcpContext(),
        'https://admin.example.test/admin/entries/12',
    );

    expect($wrapped['notes'])->toBe('Entry saved.');
    expect($wrapped['notes'])->not->toContain('admin');
});

it('still returns the payload unchanged on CP when neither URL nor cpEditUrl is available', function () {
    // Regression guard for the historical "skip wrap entirely on CP
    // with nothing to add" shortcut — the cpEditUrl support didn't
    // change that contract for callers that don't pass either.
    expect(PreviewSuggestion::wrap(['id' => 9], null, 'entry', cpContext(), null))
        ->toBe(['id' => 9]);
    expect(PreviewSuggestion::wrap(['id' => 9], null, 'entry', cpContext(), ''))
        ->toBe(['id' => 9]);
});
