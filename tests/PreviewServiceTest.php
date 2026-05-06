<?php

use markhuot\craftai\preview\PreviewService;
use markhuot\craftai\records\PreviewRequestRecord;

it('persists a new pending request and reads it back', function () {
    $service = new PreviewService();

    $id = $service->create('session-svc-1', 'tool-use-1', PreviewRequestRecord::TYPE_OPEN, [
        'url' => 'https://example.com',
    ]);

    $record = $service->find($id);
    expect($record)->not->toBeNull();
    expect($record->sessionId)->toBe('session-svc-1');
    expect($record->toolUseId)->toBe('tool-use-1');
    expect($record->type)->toBe('open');
    expect($record->status)->toBe(PreviewRequestRecord::STATUS_PENDING);
    expect($service->decodeInput($record))->toBe(['url' => 'https://example.com']);
});

it('returns the oldest pending request as the next actionable for the session', function () {
    $service = new PreviewService();

    $first = $service->create('session-actionable', null, 'open', ['url' => '/a']);
    // Drop a second request in for a different session — must not be returned.
    $service->create('session-other', null, 'open', ['url' => '/x']);
    $second = $service->create('session-actionable', null, 'get', ['fullHtml' => false]);

    $next = $service->nextActionable('session-actionable');
    expect($next)->not->toBeNull();
    expect((int) $next->id)->toBe($first);
    expect($next->type)->toBe('open');

    // Resolve the first; now `get` should be next in line.
    $service->complete($first, ['loadedAt' => 1, 'finalUrl' => '/a']);

    $next = $service->nextActionable('session-actionable');
    expect((int) $next->id)->toBe($second);
    expect($next->type)->toBe('get');
});

it('skips completed and errored rows when picking the next actionable', function () {
    $service = new PreviewService();

    $a = $service->create('session-skip', null, 'open', ['url' => '/a']);
    $service->fail($a, 'load failed');

    $b = $service->create('session-skip', null, 'open', ['url' => '/b']);
    $service->complete($b, ['loadedAt' => 1, 'finalUrl' => '/b']);

    $c = $service->create('session-skip', null, 'open', ['url' => '/c']);

    $next = $service->nextActionable('session-skip');
    expect($next)->not->toBeNull();
    expect((int) $next->id)->toBe($c);
});

it('waitFor returns immediately once the row is completed', function () {
    $service = new PreviewService();

    $id = $service->create('session-wait', null, 'open', ['url' => '/a']);
    $service->complete($id, ['loadedAt' => 1, 'finalUrl' => '/a']);

    $start = microtime(true);
    $resolved = $service->waitFor($id, 30);
    $elapsed = microtime(true) - $start;

    expect($resolved->status)->toBe(PreviewRequestRecord::STATUS_COMPLETED);
    // Should not have actually slept for any meaningful time on a hit.
    expect($elapsed)->toBeLessThan(1.0);
});

it('waitFor flips the row to errored when it times out', function () {
    $service = new PreviewService();

    $id = $service->create('session-timeout', null, 'open', ['url' => '/a']);

    $start = microtime(true);
    $resolved = $service->waitFor($id, 5); // 5s is the floor we clamp to
    $elapsed = microtime(true) - $start;

    expect($resolved->status)->toBe(PreviewRequestRecord::STATUS_ERRORED);
    expect($elapsed)->toBeGreaterThanOrEqual(4.5); // small slack for clock jitter
    $payload = json_decode($resolved->result, true);
    expect($payload['error'])->toContain('Timed out');
});

it('waitFor short-circuits when the abort hook returns true', function () {
    $service = new PreviewService();

    $id = $service->create('session-abort', null, 'open', ['url' => '/a']);

    // Always-aborting hook resolves on the first poll, so this should return
    // in well under the timeout floor (5s).
    $start = microtime(true);
    $resolved = $service->waitFor($id, 30, shouldAbort: static fn (): bool => true);
    $elapsed = microtime(true) - $start;

    expect($resolved->status)->toBe(PreviewRequestRecord::STATUS_ERRORED);
    expect(json_decode($resolved->result, true)['error'])->toBe('Stopped by user.');
    expect($elapsed)->toBeLessThan(1.0);
});

it('complete is idempotent — re-completing a finished row is a no-op', function () {
    $service = new PreviewService();

    $id = $service->create('session-idem', null, 'get', ['fullHtml' => false]);
    $service->complete($id, ['content' => 'original']);
    $service->complete($id, ['content' => 'overwrite-attempt']);

    $record = $service->find($id);
    expect($record->status)->toBe(PreviewRequestRecord::STATUS_COMPLETED);
    expect(json_decode($record->result, true)['content'])->toBe('original');
});
