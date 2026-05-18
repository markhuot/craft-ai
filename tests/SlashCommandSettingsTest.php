<?php

use markhuot\craftai\models\Command;
use markhuot\craftai\models\Settings;

it('round-trips command rows through set/get', function () {
    $settings = new Settings();
    $settings->setCommands([
        [
            'name' => 'editorial-review',
            'prompt' => 'Review this entry.',
            'enabled' => true,
        ],
    ]);

    $models = $settings->getCommands();
    expect($models)->toHaveCount(1);
    expect($models[0]->name)->toBe('editorial-review');
    expect($models[0]->prompt)->toBe('Review this entry.');
    expect($models[0]->enabled)->toBeTrue();
    expect($models[0]->uid)->not->toBe('');
});

it('seeds the translate and editorial-review defaults when no commands have been configured', function () {
    $settings = new Settings();

    $names = array_map(static fn (Command $c) => $c->name, $settings->getCommands());

    expect($names)->toBe(['translate', 'editorial-review']);
});

it('treats an explicit empty list as "user cleared the table" rather than re-seeding defaults', function () {
    $settings = new Settings();
    $settings->setCommands([]);

    expect($settings->getCommands())->toBe([]);
});

it('drops rows with empty names or empty prompts (the "+ added but never filled" case)', function () {
    $settings = new Settings();
    $settings->setCommands([
        ['name' => 'a', 'prompt' => 'real'],
        ['name' => '',  'prompt' => 'no name'],
        ['name' => 'b', 'prompt' => '   '],
        ['name' => 'c', 'prompt' => ''],
    ]);

    expect($settings->getCommands())->toHaveCount(1);
    expect($settings->getCommands()[0]->name)->toBe('a');
});

it('normalizes user-entered names into slug-safe form', function () {
    $settings = new Settings();
    $settings->setCommands([
        ['name' => 'Editorial Review', 'prompt' => 'p'],
        ['name' => 'TRANSLATE_NOW',    'prompt' => 'p'],
        ['name' => '  spaced  out  ',  'prompt' => 'p'],
    ]);

    $names = array_map(static fn (Command $c) => $c->name, $settings->getCommands());
    expect($names)->toBe(['editorial-review', 'translate-now', 'spaced-out']);
});

it('coerces string boolean values from form posts', function () {
    $settings = new Settings();
    $settings->setCommands([
        ['name' => 'a', 'prompt' => 'p', 'enabled' => '1'],
        ['name' => 'b', 'prompt' => 'p', 'enabled' => '0'],
        ['name' => 'c', 'prompt' => 'p', 'enabled' => 'on'],
    ]);

    $models = $settings->getCommands();
    expect($models[0]->enabled)->toBeTrue();
    expect($models[1]->enabled)->toBeFalse();
    expect($models[2]->enabled)->toBeTrue();
});

it('drops duplicate names (first one wins)', function () {
    $settings = new Settings();
    $settings->setCommands([
        ['name' => 'same', 'prompt' => 'first'],
        ['name' => 'same', 'prompt' => 'second'],
    ]);

    $models = $settings->getCommands();
    expect($models)->toHaveCount(1);
    expect($models[0]->prompt)->toBe('first');
});

it('rejects reserved built-in names at validate time', function () {
    $cmd = Command::fromArray(['name' => 'compact', 'prompt' => 'shadow me']);
    expect($cmd->validate())->toBeFalse();
    expect($cmd->getFirstErrors())->toHaveKey('name');
});

it('rejects non-slug-safe characters at validate time', function () {
    // setCommands normalizes, so to surface a validation failure we set
    // the property directly and validate the inner model.
    $cmd = new Command();
    $cmd->name = 'Has Spaces';
    $cmd->prompt = 'p';
    expect($cmd->validate())->toBeFalse();
    expect($cmd->getFirstErrors())->toHaveKey('name');
});

it('preserves uid when round-tripping', function () {
    $settings = new Settings();
    $settings->setCommands([
        ['uid' => 'abc-123', 'name' => 'keepme', 'prompt' => 'p'],
    ]);

    $models = $settings->getCommands();
    expect($models[0]->uid)->toBe('abc-123');
});

it('round-trips through the model toArray() — the same path Craft uses to write project config', function () {
    $original = new Settings();
    $original->setCommands([
        ['name' => 'one', 'prompt' => 'first'],
        ['name' => 'two', 'prompt' => 'second', 'enabled' => false],
    ]);

    // This is the exact serialization Craft hands to ProjectConfig::set().
    $serialized = $original->toArray();
    expect($serialized)->toHaveKey('commands');
    expect($serialized['commands'])->toHaveCount(2);

    // And this is the path back: setAttributes() is what Plugins service
    // calls when re-applying project config on the receiving env.
    $restored = new Settings();
    $restored->setAttributes($serialized, false);

    $names = array_map(static fn (Command $c) => $c->name, $restored->getCommands());
    expect($names)->toBe(['one', 'two']);
    expect($restored->getCommands()[1]->enabled)->toBeFalse();
});

it('keeps null commands distinct from an empty array across attribute round-trip', function () {
    $settings = new Settings();
    // setAttributes with `commands: null` (what project config writes
    // when the key was never configured) must not collapse to the empty
    // sentinel — otherwise the defaults would never re-appear.
    $settings->setAttributes(['commands' => null], false);

    $names = array_map(static fn (Command $c) => $c->name, $settings->getCommands());
    expect($names)->toBe(['translate', 'editorial-review']);
});
