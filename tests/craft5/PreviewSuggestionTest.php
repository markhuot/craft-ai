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

it('returns a {_notes, data: {key}} envelope on every surface', function () {
    // Shape contract: the consumer sees one notes field (`_notes`) and the
    // element payload at `data.{key}`. No nested `notes` inside `data`,
    // regardless of surface — the LLM only has one place to look.
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=5. Use get_entry to fetch the saved record.',
        data: ['id' => 5, 'title' => 'Hi'],
        key: 'entry',
        url: 'https://example.test/hi',
        context: cpContext(),
    );

    expect($envelope)->toHaveKeys(['_notes', 'data']);
    expect($envelope['data'])->toBe(['entry' => ['id' => 5, 'title' => 'Hi']]);
});

it('folds the open_preview prompt into _notes on the CP surface when a URL is available', function () {
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=5.',
        data: ['id' => 5],
        key: 'entry',
        url: 'https://example.test/hi',
        context: cpContext(),
    );

    expect($envelope['_notes'])->toStartWith('Created entry id=5.');
    expect($envelope['_notes'])->toContain('open_preview');
    expect($envelope['_notes'])->toContain('https://example.test/hi');
});

it('passes the tool note through unchanged when no surface guidance applies on CP', function () {
    // CP with no preview URL and no cpEditUrl: no call-to-action to add,
    // so `_notes` is just the tool's note verbatim. Critically the shape
    // still includes `data.{key}` — consumers don't have to branch on
    // surface to find the element data.
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=9.',
        data: ['id' => 9],
        key: 'entry',
        url: null,
        context: cpContext(),
    );

    expect($envelope)->toBe([
        '_notes' => 'Created entry id=9.',
        'data' => ['entry' => ['id' => 9]],
    ]);

    // Empty-string URL behaves the same as null.
    $envelope2 = PreviewSuggestion::wrap(
        notes: 'Created entry id=9.',
        data: ['id' => 9],
        key: 'entry',
        url: '',
        context: cpContext(),
    );
    expect($envelope2)->toBe([
        '_notes' => 'Created entry id=9.',
        'data' => ['entry' => ['id' => 9]],
    ]);
});

it('omits the open_preview prompt on MCP even when a URL is passed in', function () {
    // MCP clients (and any non-browser surface) have no preview pane, so
    // the URL is silently dropped — `_notes` ends up as just the tool note.
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=5.',
        data: ['id' => 5],
        key: 'entry',
        url: 'https://example.test/hi',
        context: mcpContext(),
    );

    expect($envelope['_notes'])->toBe('Created entry id=5.');
    expect($envelope['_notes'])->not->toContain('open_preview');
});

it('omits the open_preview prompt on the widget surface', function () {
    // Widget is browser-based but doesn't host the iframe — only the
    // full CP chat does. So the preview suggestion is suppressed even
    // though we still wrap with the consistent envelope.
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=1.',
        data: ['id' => 1],
        key: 'entry',
        url: 'https://example.test/x',
        context: widgetContext(),
    );

    expect($envelope['_notes'])->toBe('Created entry id=1.');
    expect($envelope['_notes'])->not->toContain('open_preview');
});

it('treats an unset client like a non-CP surface (conservative default)', function () {
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=1.',
        data: ['id' => 1],
        key: 'entry',
        url: 'https://example.test/x',
        context: new ToolContext(),
    );

    expect($envelope['_notes'])->toBe('Created entry id=1.');
    expect($envelope['_notes'])->not->toContain('open_preview');
});

it('folds the cpEditUrl link instruction into _notes on the CP surface', function () {
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=5.',
        data: ['id' => 5],
        key: 'entry',
        url: 'https://example.test/hi',
        context: cpContext(),
        cpEditUrl: 'https://admin.example.test/admin/entries/news/5',
    );

    expect($envelope['_notes'])->toContain('open_preview');
    expect($envelope['_notes'])->toContain('https://example.test/hi');
    expect($envelope['_notes'])->toContain('review and edit');
    expect($envelope['_notes'])->toContain('https://admin.example.test/admin/entries/news/5');
});

it('emits just the cpEditUrl link when CP has no front-end URL', function () {
    // Sections without URI formats, draft entries on disabled sections,
    // assets on filesystems with no public URLs — all valid CP saves
    // that have no preview pane to drive but DO have an edit screen the
    // editor can be linked back to.
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=9.',
        data: ['id' => 9],
        key: 'entry',
        url: null,
        context: cpContext(),
        cpEditUrl: 'https://admin.example.test/admin/entries/9',
    );

    expect($envelope['_notes'])->not->toContain('open_preview');
    expect($envelope['_notes'])->toContain('https://admin.example.test/admin/entries/9');
});

it('folds the cpEditUrl link instruction into _notes on the widget surface', function () {
    // Widget is browser-based — it has no preview pane, but the user
    // can still click through to the CP edit screen to review.
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=11.',
        data: ['id' => 11],
        key: 'entry',
        url: 'https://example.test/x',
        context: widgetContext(),
        cpEditUrl: 'https://admin.example.test/admin/entries/11',
    );

    expect($envelope['_notes'])->not->toContain('open_preview');
    expect($envelope['_notes'])->toContain('review and edit');
    expect($envelope['_notes'])->toContain('https://admin.example.test/admin/entries/11');
});

it('omits the cpEditUrl link on MCP (no browser to click through)', function () {
    $envelope = PreviewSuggestion::wrap(
        notes: 'Created entry id=12.',
        data: ['id' => 12],
        key: 'entry',
        url: 'https://example.test/x',
        context: mcpContext(),
        cpEditUrl: 'https://admin.example.test/admin/entries/12',
    );

    expect($envelope['_notes'])->toBe('Created entry id=12.');
    expect($envelope['_notes'])->not->toContain('admin');
});

it('still wraps under data.{key} on CP when neither URL nor cpEditUrl is available', function () {
    // Regression guard for the surface-consistent contract: callers
    // that pass neither a preview URL nor a cpEditUrl on CP still get
    // the same `{_notes, data: {key}}` envelope. The note degrades to
    // the tool note unchanged.
    expect(PreviewSuggestion::wrap(
        notes: 'Created entry id=9.',
        data: ['id' => 9],
        key: 'entry',
        url: null,
        context: cpContext(),
        cpEditUrl: null,
    ))->toBe([
        '_notes' => 'Created entry id=9.',
        'data' => ['entry' => ['id' => 9]],
    ]);

    expect(PreviewSuggestion::wrap(
        notes: 'Created entry id=9.',
        data: ['id' => 9],
        key: 'entry',
        url: null,
        context: cpContext(),
        cpEditUrl: '',
    ))->toBe([
        '_notes' => 'Created entry id=9.',
        'data' => ['entry' => ['id' => 9]],
    ]);
});

it('uses the provided key as the noun in data', function () {
    // The wrap is generic over the noun — same fn, different data shape
    // depending on what tool calls it.
    expect(PreviewSuggestion::wrap(
        notes: 'Created draft draftId=1.',
        data: ['id' => 1],
        key: 'draft',
        url: null,
        context: cpContext(),
    )['data'])->toBe(['draft' => ['id' => 1]]);

    expect(PreviewSuggestion::wrap(
        notes: 'Created asset id=2.',
        data: ['id' => 2],
        key: 'asset',
        url: null,
        context: cpContext(),
    )['data'])->toBe(['asset' => ['id' => 2]]);
});
