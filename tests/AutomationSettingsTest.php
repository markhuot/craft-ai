<?php

use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Settings;

it('round-trips automation rows through set/get', function () {
    $settings = new Settings();
    $settings->setAutomations([
        [
            'name' => 'Review drafts',
            'event' => Automation::EVENT_DRAFT_SAVED,
            'sectionHandle' => 'posts',
            'prompt' => 'Review this draft.',
            'enabled' => true,
        ],
    ]);

    $models = $settings->getAutomations();
    expect($models)->toHaveCount(1);
    expect($models[0]->name)->toBe('Review drafts');
    expect($models[0]->event)->toBe(Automation::EVENT_DRAFT_SAVED);
    expect($models[0]->sectionHandle)->toBe('posts');
    expect($models[0]->prompt)->toBe('Review this draft.');
    expect($models[0]->enabled)->toBeTrue();
    expect($models[0]->uid)->not->toBe('');
});

it('drops rows with empty prompts (the "+ added but never filled" case)', function () {
    $settings = new Settings();
    $settings->setAutomations([
        ['event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'real'],
        ['event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => '   '],
        ['event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => ''],
    ]);

    expect($settings->getAutomations())->toHaveCount(1);
});

it('drops rows with empty events', function () {
    $settings = new Settings();
    $settings->setAutomations([
        ['event' => '', 'prompt' => 'orphan'],
        ['event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'valid'],
    ]);

    expect($settings->getAutomations())->toHaveCount(1);
});

it('coerces string boolean values from form posts', function () {
    $settings = new Settings();
    $settings->setAutomations([
        ['event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'p', 'enabled' => '1'],
        ['event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'q', 'enabled' => '0'],
        ['event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'r', 'enabled' => 'on'],
    ]);

    $models = $settings->getAutomations();
    expect($models[0]->enabled)->toBeTrue();
    expect($models[1]->enabled)->toBeFalse();
    expect($models[2]->enabled)->toBeTrue();
});

it('validates that each automation has a valid event', function () {
    $settings = new Settings();
    $settings->automations = [
        ['event' => 'bogus.event', 'prompt' => 'something', 'enabled' => true],
    ];

    expect($settings->validate())->toBeFalse();
    expect($settings->getFirstErrors())->not->toBeEmpty();
});

it('preserves uid when round-tripping', function () {
    $settings = new Settings();
    $settings->setAutomations([
        ['uid' => 'abc-123', 'event' => Automation::EVENT_DRAFT_SAVED, 'prompt' => 'keep me'],
    ]);

    $models = $settings->getAutomations();
    expect($models[0]->uid)->toBe('abc-123');
});

it('lists supported events in the dropdown choices', function () {
    $choices = Automation::eventChoices();
    expect($choices)->toHaveKey(Automation::EVENT_ENTRY_SAVED);
    expect($choices)->toHaveKey(Automation::EVENT_DRAFT_SAVED);
    expect($choices)->toHaveKey(Automation::EVENT_DRAFT_APPLIED);
    expect($choices)->toHaveKey(Automation::EVENT_ENTRY_DELETED);
    expect($choices)->toHaveKey(Automation::EVENT_ASSET_SAVED);
});
