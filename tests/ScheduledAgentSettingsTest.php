<?php

use markhuot\craftai\models\ScheduledAgent;
use markhuot\craftai\models\Settings;

it('round-trips a scheduled agent through toConfigArray and fromArray', function () {
    $agent = ScheduledAgent::fromArray([
        'name' => 'Weekly LLM roundup',
        'prompt' => 'Create a post about the latest advancements in LLM technology.',
        'frequency' => ScheduledAgent::FREQUENCY_WEEKLY,
        'time' => '09:00',
        'dayOfWeek' => 1,
        'userId' => 42,
        'enabled' => true,
    ]);

    $copy = ScheduledAgent::fromArray($agent->toConfigArray());

    expect($copy->uid)->toBe($agent->uid);
    expect($copy->name)->toBe('Weekly LLM roundup');
    expect($copy->frequency)->toBe(ScheduledAgent::FREQUENCY_WEEKLY);
    expect($copy->time)->toBe('09:00');
    expect($copy->dayOfWeek)->toBe(1);
    expect($copy->userId)->toBe(42);
    expect($copy->enabled)->toBeTrue();
});

it('mints a uid when none is supplied and preserves a supplied one', function () {
    $fresh = ScheduledAgent::fromArray(['prompt' => 'p']);
    expect($fresh->uid)->not->toBe('');

    $existing = ScheduledAgent::fromArray(['uid' => 'abc-123', 'prompt' => 'p']);
    expect($existing->uid)->toBe('abc-123');
});

it('derives the right cron expression per frequency', function () {
    $base = ['prompt' => 'p', 'time' => '14:30', 'minute' => 5, 'dayOfWeek' => 3, 'dayOfMonth' => 15];

    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'hourly'])->toCronExpression())->toBe('5 * * * *');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'daily'])->toCronExpression())->toBe('30 14 * * *');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'weekly'])->toCronExpression())->toBe('30 14 * * 3');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'monthly'])->toCronExpression())->toBe('30 14 15 * *');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'custom', 'cronExpression' => '0 9 */2 * 1'])->toCronExpression())->toBe('0 9 */2 * 1');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'once', 'runDate' => '2026-06-10T09:00'])->toCronExpression())->toBeNull();
});

it('computes the next run for a repeating schedule', function () {
    $agent = ScheduledAgent::fromArray([
        'prompt' => 'p',
        'frequency' => ScheduledAgent::FREQUENCY_WEEKLY,
        'time' => '09:00',
        'dayOfWeek' => 1, // Monday
    ]);

    // 2026-06-04 is a Thursday.
    $after = new \DateTimeImmutable('2026-06-04 12:00:00', new \DateTimeZone('UTC'));

    expect($agent->nextRunAfter($after)?->format('Y-m-d H:i'))->toBe('2026-06-08 09:00');
});

it('treats a one-time schedule as exhausted once its moment passes', function () {
    $agent = ScheduledAgent::fromArray([
        'prompt' => 'p',
        'frequency' => ScheduledAgent::FREQUENCY_ONCE,
        'runDate' => '2026-06-10T09:00',
    ]);

    $tz = new \DateTimeZone('UTC');
    $before = new \DateTimeImmutable('2026-06-09 00:00:00', $tz);
    $afterTheFact = new \DateTimeImmutable('2026-06-10 09:00:00', $tz);

    expect($agent->nextRunAfter($before)?->format('Y-m-d H:i'))->toBe('2026-06-10 09:00');
    expect($agent->nextRunAfter($afterTheFact))->toBeNull();
});

it('validates per-frequency requirements', function () {
    $once = ScheduledAgent::fromArray(['prompt' => 'p', 'frequency' => 'once']);
    expect($once->validate())->toBeFalse();
    expect($once->getErrors('runDate'))->not->toBeEmpty();

    $custom = ScheduledAgent::fromArray(['prompt' => 'p', 'frequency' => 'custom', 'cronExpression' => 'not a cron']);
    expect($custom->validate())->toBeFalse();
    expect($custom->getErrors('cronExpression'))->not->toBeEmpty();

    $badTime = ScheduledAgent::fromArray(['prompt' => 'p', 'frequency' => 'daily', 'time' => '25:99']);
    expect($badTime->validate())->toBeFalse();
    expect($badTime->getErrors('time'))->not->toBeEmpty();

    $noPrompt = ScheduledAgent::fromArray(['prompt' => '', 'frequency' => 'daily']);
    expect($noPrompt->validate())->toBeFalse();

    $valid = ScheduledAgent::fromArray([
        'prompt' => 'p',
        'frequency' => 'weekly',
        'time' => '09:00',
        'dayOfWeek' => 1,
    ]);
    expect($valid->validate())->toBeTrue();
});

it('does not demand once/custom fields from other frequencies', function () {
    // A weekly schedule with an empty runDate and cronExpression is fine —
    // those fields belong to other frequencies.
    $agent = ScheduledAgent::fromArray(['prompt' => 'p', 'frequency' => 'weekly']);

    expect($agent->validate())->toBeTrue();
});

it('describes each schedule in plain language', function () {
    $base = ['prompt' => 'p', 'time' => '09:00', 'minute' => 5, 'dayOfWeek' => 1, 'dayOfMonth' => 15];

    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'hourly'])->describeSchedule())->toBe('Hourly at :05');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'daily'])->describeSchedule())->toBe('Daily at 9:00 AM');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'weekly'])->describeSchedule())->toBe('Weekly on Monday at 9:00 AM');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'monthly'])->describeSchedule())->toBe('Monthly on day 15 at 9:00 AM');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'custom', 'cronExpression' => '0 9 * * 1'])->describeSchedule())->toBe('Cron: 0 9 * * 1');
    expect(ScheduledAgent::fromArray([...$base, 'frequency' => 'once', 'runDate' => '2026-06-10T09:00'])->describeSchedule())->toBe('Once at Jun 10, 2026 9:00 AM');
});

it('accepts a space-separated runDate from hand-edited config', function () {
    $agent = ScheduledAgent::fromArray([
        'prompt' => 'p',
        'frequency' => 'once',
        'runDate' => '2026-06-10 09:00',
    ]);

    expect($agent->validate())->toBeTrue();
    expect($agent->runDateMoment(new \DateTimeZone('UTC'))?->format('Y-m-d H:i'))->toBe('2026-06-10 09:00');
});

it('stores scheduled agents on the Settings model, dropping empty prompts', function () {
    $settings = new Settings();
    $settings->setScheduledAgents([
        ['name' => 'keep', 'prompt' => 'Do the thing.', 'frequency' => 'daily'],
        ['name' => 'drop', 'prompt' => '   ', 'frequency' => 'daily'],
        'garbage',
    ]);

    $agents = $settings->getScheduledAgents();
    expect($agents)->toHaveCount(1);
    expect($agents[0]->name)->toBe('keep');
});

it('routes scheduledAgents through the setter on setAttributes', function () {
    // The same Yii typed-public-property pitfall the commands/automations
    // setters guard against: setAttributes must normalize rows (mint UIDs,
    // drop blanks) rather than writing raw form posts to the property.
    $settings = new Settings();
    $settings->setAttributes([
        'scheduledAgents' => [
            ['name' => 'a', 'prompt' => 'Prompt A', 'frequency' => 'weekly'],
            ['name' => 'blank', 'prompt' => ''],
        ],
    ], false);

    expect($settings->scheduledAgents)->toHaveCount(1);
    expect($settings->scheduledAgents[0]['uid'] ?? '')->not->toBe('');
});

it('keeps a stable uid across repeated reads', function () {
    $settings = new Settings();
    $settings->setScheduledAgents([
        ['name' => 'stable', 'prompt' => 'p', 'frequency' => 'daily'],
    ]);

    $first = $settings->getScheduledAgents()[0]->uid;
    $second = $settings->getScheduledAgents()[0]->uid;

    expect($first)->toBe($second);
});

it('aggregates row validation errors on the Settings model', function () {
    $settings = new Settings();
    $settings->setScheduledAgents([
        ['name' => 'bad', 'prompt' => 'p', 'frequency' => 'custom', 'cronExpression' => 'nope'],
    ]);

    expect($settings->validate())->toBeFalse();
    expect($settings->getErrors('scheduledAgents'))->not->toBeEmpty();
});
