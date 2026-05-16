<?php

use markhuot\craftai\helpers\NestedEntryResolver;

it('recognizes the structured CKEditor payload shape', function () {
    expect(NestedEntryResolver::isStructured([
        'html' => '<p>hi</p>',
        'entries' => ['new1' => []],
    ]))->toBeTrue();
});

it('rejects plain string values', function () {
    expect(NestedEntryResolver::isStructured('<p>hi</p>'))->toBeFalse();
});

it('rejects partial shapes', function () {
    expect(NestedEntryResolver::isStructured(['html' => '<p>hi</p>']))->toBeFalse();
    expect(NestedEntryResolver::isStructured(['entries' => []]))->toBeFalse();
});

it('rejects shapes where entries is not an array', function () {
    expect(NestedEntryResolver::isStructured([
        'html' => '<p>hi</p>',
        'entries' => 'oops',
    ]))->toBeFalse();
});

it('rejects shapes where html is not a string', function () {
    expect(NestedEntryResolver::isStructured([
        'html' => 12,
        'entries' => [],
    ]))->toBeFalse();
});
