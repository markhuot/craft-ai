<?php

use markhuot\craftai\scheduling\CronExpression;

function cronMoment(string $datetime): \DateTimeImmutable
{
    return new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
}

it('accepts valid expressions', function (string $expression) {
    expect(CronExpression::isValid($expression))->toBeTrue();
})->with([
    '* * * * *',
    '0 9 * * 1',
    '*/15 * * * *',
    '0 0 1 1 *',
    '30 4 1,15 * 5',
    '0 9-17 * * MON-FRI',
    '0 12 * JAN,JUL *',
    '5/10 * * * *',
    '0 0 * * 7',
]);

it('rejects malformed expressions', function (string $expression) {
    expect(CronExpression::isValid($expression))->toBeFalse();
})->with([
    '',
    '* * * *',          // 4 fields
    '* * * * * *',      // 6 fields
    '60 * * * *',       // minute out of range
    '* 24 * * *',       // hour out of range
    '* * 0 * *',        // day-of-month out of range
    '* * * 13 *',       // month out of range
    '* * * * 8',        // weekday out of range
    'a * * * *',        // non-numeric
    '5-1 * * * *',      // inverted range
    '*/0 * * * *',      // zero step
    '0 9 * * MONDAY',   // full names not supported
]);

it('matches the exact minute and nothing else', function () {
    $cron = CronExpression::parse('30 9 * * *');

    expect($cron->matches(cronMoment('2026-06-04 09:30:00')))->toBeTrue();
    expect($cron->matches(cronMoment('2026-06-04 09:30:59')))->toBeTrue(); // seconds ignored
    expect($cron->matches(cronMoment('2026-06-04 09:31:00')))->toBeFalse();
    expect($cron->matches(cronMoment('2026-06-04 10:30:00')))->toBeFalse();
});

it('computes the next run for a weekly schedule', function () {
    // 9:00 AM Mondays. 2026-06-04 is a Thursday.
    $cron = CronExpression::parse('0 9 * * 1');

    $next = $cron->nextRunDate(cronMoment('2026-06-04 12:00:00'));

    expect($next?->format('Y-m-d H:i'))->toBe('2026-06-08 09:00');
    expect($next?->format('l'))->toBe('Monday');
});

it('returns a strictly future result even when "after" is itself a match', function () {
    $cron = CronExpression::parse('0 9 * * 1');

    // 2026-06-08 09:00 is a matching Monday; next must be a week later.
    $next = $cron->nextRunDate(cronMoment('2026-06-08 09:00:00'));

    expect($next?->format('Y-m-d H:i'))->toBe('2026-06-15 09:00');
});

it('resolves later slots within the same hour and day', function () {
    $cron = CronExpression::parse('*/15 * * * *');

    expect($cron->nextRunDate(cronMoment('2026-06-04 09:16:00'))?->format('H:i'))->toBe('09:30');
    expect($cron->nextRunDate(cronMoment('2026-06-04 09:45:00'))?->format('H:i'))->toBe('10:00');
});

it('rolls into the next month when the day has passed', function () {
    $cron = CronExpression::parse('0 0 15 * *');

    $next = $cron->nextRunDate(cronMoment('2026-06-20 00:00:00'));

    expect($next?->format('Y-m-d H:i'))->toBe('2026-07-15 00:00');
});

it('skips months without the scheduled day-of-month', function () {
    // Day 31 doesn't exist in June; the next slot lands in July.
    $cron = CronExpression::parse('0 0 31 * *');

    $next = $cron->nextRunDate(cronMoment('2026-06-01 00:00:00'));

    expect($next?->format('Y-m-d H:i'))->toBe('2026-07-31 00:00');
});

it('honors month and weekday names', function () {
    $cron = CronExpression::parse('0 12 * JUL FRI');

    $next = $cron->nextRunDate(cronMoment('2026-06-04 00:00:00'));

    expect($next?->format('Y-m-d'))->toBe('2026-07-03');
    expect($next?->format('l'))->toBe('Friday');
});

it('treats weekday 7 as Sunday', function () {
    $seven = CronExpression::parse('0 0 * * 7');
    $zero = CronExpression::parse('0 0 * * 0');
    $sunday = cronMoment('2026-06-07 00:00:00'); // a Sunday

    expect($seven->matches($sunday))->toBeTrue();
    expect($zero->matches($sunday))->toBeTrue();
});

it('ORs day-of-month and day-of-week when both are restricted', function () {
    // Vixie cron: "0 0 13 * 5" fires on the 13th AND on every Friday.
    $cron = CronExpression::parse('0 0 13 * 5');

    expect($cron->matches(cronMoment('2026-06-13 00:00:00')))->toBeTrue();  // a Saturday, but the 13th
    expect($cron->matches(cronMoment('2026-06-05 00:00:00')))->toBeTrue();  // a Friday, not the 13th
    expect($cron->matches(cronMoment('2026-06-04 00:00:00')))->toBeFalse(); // a Thursday, not the 13th
});

it('requires only the restricted day field when the other is a wildcard', function () {
    $cron = CronExpression::parse('0 0 * * 5');

    expect($cron->matches(cronMoment('2026-06-05 00:00:00')))->toBeTrue();  // Friday
    expect($cron->matches(cronMoment('2026-06-13 00:00:00')))->toBeFalse(); // Saturday the 13th
});

it('handles stepped ranges and lists', function () {
    $cron = CronExpression::parse('0 9-17/4 * * *');

    expect($cron->matches(cronMoment('2026-06-04 09:00:00')))->toBeTrue();
    expect($cron->matches(cronMoment('2026-06-04 13:00:00')))->toBeTrue();
    expect($cron->matches(cronMoment('2026-06-04 17:00:00')))->toBeTrue();
    expect($cron->matches(cronMoment('2026-06-04 11:00:00')))->toBeFalse();

    $list = CronExpression::parse('0 0 1,15 * *');
    expect($list->matches(cronMoment('2026-06-15 00:00:00')))->toBeTrue();
    expect($list->matches(cronMoment('2026-06-16 00:00:00')))->toBeFalse();
});

it('handles a sparse once-a-year schedule', function () {
    $cron = CronExpression::parse('0 0 1 1 *');

    $next = $cron->nextRunDate(cronMoment('2026-06-04 00:00:00'));

    expect($next?->format('Y-m-d H:i'))->toBe('2027-01-01 00:00');
});

it('finds Feb 29 across leap years', function () {
    $cron = CronExpression::parse('0 0 29 2 *');

    $next = $cron->nextRunDate(cronMoment('2026-06-04 00:00:00'));

    expect($next?->format('Y-m-d'))->toBe('2028-02-29');
});

it('returns null for unsatisfiable expressions instead of looping forever', function () {
    $cron = CronExpression::parse('0 0 30 2 *'); // Feb 30 never exists

    expect($cron->nextRunDate(cronMoment('2026-06-04 00:00:00')))->toBeNull();
});

it('evaluates in the timezone of the supplied moment', function () {
    $cron = CronExpression::parse('0 9 * * *');
    $after = new \DateTimeImmutable('2026-06-04 08:00:00', new \DateTimeZone('America/New_York'));

    $next = $cron->nextRunDate($after);

    expect($next?->format('Y-m-d H:i'))->toBe('2026-06-04 09:00');
    expect($next?->getTimezone()->getName())->toBe('America/New_York');
});
