<?php

use markhuot\craftai\diff\DiffRenderer;

it('renders a self-contained, script-free document', function () {
    $diff = [
        'a' => ['ref' => 'rev:1', 'label' => 'Revision 1', 'savedBy' => 'jane', 'dateUpdated' => '2026-05-01T10:00:00+00:00'],
        'b' => ['ref' => 'current', 'label' => 'Current', 'savedBy' => null, 'dateUpdated' => '2026-05-02T10:00:00+00:00'],
        'summary' => ['changed' => 1, 'added' => 0, 'removed' => 0, 'unchanged' => 1],
        'fields' => [
            ['handle' => 'title', 'name' => 'Title', 'type' => 'System', 'kind' => 'text', 'status' => 'changed', 'detail' => ['textDiff' => [
                ['op' => 'eq', 'text' => 'Hello '],
                ['op' => 'del', 'text' => 'World'],
                ['op' => 'ins', 'text' => 'There'],
            ]]],
            ['handle' => 'body', 'name' => 'Body', 'type' => 'Plain Text', 'kind' => 'text', 'status' => 'unchanged', 'detail' => []],
        ],
    ];

    $html = (new DiffRenderer())->render($diff, 'Compare Revisions');

    expect(strtolower($html))->toContain('<!doctype html');
    expect($html)->not->toContain('<script');
    expect($html)->toContain('<del>World</del>');
    expect($html)->toContain('<ins>There</ins>');
    expect($html)->toContain('Revision 1');
    expect($html)->toContain('Current');
    expect($html)->toContain('Unchanged: Body');
});

it('escapes HTML in field content so authored markup cannot execute', function () {
    $diff = [
        'a' => ['label' => 'A'],
        'b' => ['label' => 'B'],
        'summary' => ['changed' => 1, 'added' => 0, 'removed' => 0, 'unchanged' => 0],
        'fields' => [
            ['handle' => 'body', 'name' => 'Body', 'type' => 'CKEditor', 'kind' => 'text', 'status' => 'changed', 'detail' => ['textDiff' => [
                ['op' => 'ins', 'text' => '<script>alert(1)</script>'],
            ]]],
        ],
    ];

    $html = (new DiffRenderer())->render($diff, 'X');

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('&lt;script&gt;');
});

it('renders relation add/remove and matrix block changes', function () {
    $diff = [
        'a' => ['label' => 'Revision 3'],
        'b' => ['label' => 'Current'],
        'summary' => ['changed' => 2, 'added' => 0, 'removed' => 0, 'unchanged' => 0],
        'fields' => [
            ['handle' => 'related', 'name' => 'Related', 'type' => 'Entries', 'kind' => 'relation', 'status' => 'changed', 'detail' => [
                'added' => [['id' => 5, 'title' => 'New Post']],
                'removed' => [['id' => 2, 'title' => 'Old Post']],
                'reordered' => false,
                'from' => [['id' => 2, 'title' => 'Old Post']],
                'to' => [['id' => 5, 'title' => 'New Post']],
            ]],
            ['handle' => 'builder', 'name' => 'Builder', 'type' => 'Matrix', 'kind' => 'matrix', 'status' => 'changed', 'detail' => [
                'reordered' => false,
                'blocks' => [
                    ['blockId' => '42', 'type' => 'text', 'status' => 'changed', 'fields' => [
                        ['handle' => 'copy', 'name' => 'Copy', 'type' => 'Plain Text', 'kind' => 'text', 'status' => 'changed', 'detail' => ['textDiff' => [
                            ['op' => 'del', 'text' => 'before'],
                            ['op' => 'ins', 'text' => 'after'],
                        ]]],
                    ]],
                    ['blockId' => '77', 'type' => 'heading', 'status' => 'added', 'fields' => []],
                ],
            ]],
        ],
    ];

    $html = (new DiffRenderer())->render($diff, 'X');

    expect($html)->toContain('New Post');
    expect($html)->toContain('Old Post');
    expect($html)->toContain('before');
    expect($html)->toContain('after');
    expect($html)->toContain('heading');
});
