<?php

use markhuot\craftai\Plugin;

it('registers the search_the_web tool in the plugin registry by default', function () {
    // Sanity check that the resolver wires through to the actual plugin
    // boot — with the dev config omitting `searchProviders` entirely, the
    // default-on behavior should make the tool visible to the CP.
    $registry = Plugin::getInstance()->getToolRegistry();

    $names = array_map(fn ($d) => $d->name, $registry->descriptors());

    expect($names)->toContain('search_the_web');
});

it('defaults to brave when searchProviders is not set in config', function () {
    $resolved = Plugin::resolveSearchProvidersConfig(['provider' => 'mock']);

    expect($resolved)->not->toBeNull();
    expect($resolved['default'])->toBe('brave');
});

it('defaults to brave when searchProviders is an empty array', function () {
    $resolved = Plugin::resolveSearchProvidersConfig(['searchProviders' => []]);

    expect($resolved)->not->toBeNull();
    expect($resolved['default'])->toBe('brave');
});

it('disables the tool when searchProviders is explicitly null', function () {
    expect(Plugin::resolveSearchProvidersConfig(['searchProviders' => null]))->toBeNull();
});

it('disables the tool when default is explicitly null', function () {
    expect(Plugin::resolveSearchProvidersConfig([
        'searchProviders' => ['default' => null],
    ]))->toBeNull();
});

it('honors a non-default provider as the default backend', function () {
    $resolved = Plugin::resolveSearchProvidersConfig([
        'searchProviders' => ['default' => 'duckduckgo'],
    ]);

    expect($resolved)->not->toBeNull();
    expect($resolved['default'])->toBe('duckduckgo');
});

it('passes per-provider config blocks through to the resolver output', function () {
    $resolved = Plugin::resolveSearchProvidersConfig([
        'searchProviders' => [
            'brave' => ['baseUrl' => 'https://search.brave.example'],
            'duckduckgo' => ['baseUrl' => 'https://html.example.com'],
        ],
    ]);

    expect($resolved)->not->toBeNull();
    expect($resolved['brave'])->toBe(['baseUrl' => 'https://search.brave.example']);
    expect($resolved['duckduckgo'])->toBe(['baseUrl' => 'https://html.example.com']);
});

it('throws on a typo in a provider key so misnamed overrides are caught', function () {
    expect(fn () => Plugin::resolveSearchProvidersConfig([
        'searchProviders' => ['brav' => []],
    ]))->toThrow(\RuntimeException::class, 'brav');
});

it('throws when default names an unsupported provider', function () {
    // Google scraping is dead — this should be rejected so users don't
    // silently end up with a non-functional default.
    expect(fn () => Plugin::resolveSearchProvidersConfig([
        'searchProviders' => ['default' => 'google'],
    ]))->toThrow(\RuntimeException::class, 'google');
});

it('treats a non-array searchProviders value as defaults rather than bricking boot', function () {
    // A user accidentally writes a string. Be forgiving — boot the plugin
    // with defaults and let them fix it later, rather than crashing.
    $resolved = Plugin::resolveSearchProvidersConfig(['searchProviders' => 'oops']);

    expect($resolved)->not->toBeNull();
    expect($resolved['default'])->toBe('brave');
});

it('treats an empty-string default as the brave default', function () {
    $resolved = Plugin::resolveSearchProvidersConfig([
        'searchProviders' => ['default' => ''],
    ]);

    expect($resolved)->not->toBeNull();
    expect($resolved['default'])->toBe('brave');
});
