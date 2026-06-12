<?php

namespace markhuot\craftai\scheduling;

/**
 * A minimal, dependency-free 5-field cron expression engine.
 *
 * Supports the standard Vixie-cron grammar for each field — `*`, single
 * values, ranges (`1-5`), steps (`*\/15`, `10-50/10`, `5/10`), comma
 * lists, and the 3-letter month / weekday names (`JAN`, `MON`). Weekday
 * `7` is normalized to `0` (both mean Sunday).
 *
 * Day-of-month vs. day-of-week follows the classic OR rule: when *both*
 * fields are restricted (neither is `*`), a day matches if either field
 * matches; when only one is restricted, that one decides.
 *
 * Why not `dragonmantank/cron-expression`? Pulling it in would add a
 * runtime dependency to every consumer of the plugin for ~200 lines of
 * logic we can own and test directly. The scheduled-agents feature only
 * needs "does this minute match" and "when is the next match", both
 * implemented here.
 *
 * All evaluation is wall-clock in the timezone of the DateTimeImmutable
 * passed in — callers should pass moments in Craft's system timezone so
 * "9:00 AM" means 9 AM site time.
 */
final class CronExpression
{
    private const MONTH_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    private const WEEKDAY_NAMES = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /**
     * Hard cap on the day-by-day scan in {@see nextRunDate}. Five years
     * covers every satisfiable expression (including `* * 29 2 *`, whose
     * gaps max out at 8 years only for impossible DOM/month combos like
     * Feb 30 — those exhaust the cap and return null instead of looping
     * forever).
     */
    private const MAX_DAY_SCAN = 366 * 5;

    /**
     * @param array<int, true> $minutes  set of matching minutes (0-59)
     * @param array<int, true> $hours    set of matching hours (0-23)
     * @param array<int, true> $days     set of matching days of month (1-31)
     * @param array<int, true> $months   set of matching months (1-12)
     * @param array<int, true> $weekdays set of matching weekdays (0-6, 0 = Sunday)
     */
    private function __construct(
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $days,
        private readonly array $months,
        private readonly array $weekdays,
        private readonly bool $dayRestricted,
        private readonly bool $weekdayRestricted,
    ) {}

    /**
     * @throws \InvalidArgumentException when the expression doesn't parse
     */
    public static function parse(string $expression): self
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) !== 5) {
            throw new \InvalidArgumentException(
                "Cron expression must have exactly 5 fields (minute hour day month weekday), got \"{$expression}\".",
            );
        }

        [$minute, $hour, $day, $month, $weekday] = $fields;

        return new self(
            minutes: self::parseField($minute, 0, 59, [], 'minute'),
            hours: self::parseField($hour, 0, 23, [], 'hour'),
            days: self::parseField($day, 1, 31, [], 'day-of-month'),
            months: self::parseField($month, 1, 12, self::MONTH_NAMES, 'month'),
            weekdays: self::parseField($weekday, 0, 7, self::WEEKDAY_NAMES, 'day-of-week'),
            dayRestricted: $day !== '*',
            weekdayRestricted: $weekday !== '*',
        );
    }

    public static function isValid(string $expression): bool
    {
        try {
            self::parse($expression);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Does this expression match the given moment, at minute granularity?
     * Seconds are ignored — cron has no second field.
     */
    public function matches(\DateTimeImmutable $moment): bool
    {
        if (! isset($this->minutes[(int) $moment->format('i')])) {
            return false;
        }
        if (! isset($this->hours[(int) $moment->format('G')])) {
            return false;
        }
        if (! isset($this->months[(int) $moment->format('n')])) {
            return false;
        }

        return $this->dayMatches($moment);
    }

    /**
     * The next matching minute strictly after `$after`, in `$after`'s
     * timezone. Returns null when no match exists within the scan bound
     * (only possible for unsatisfiable expressions like `0 0 30 2 *`).
     *
     * Scans day-by-day (cheap field checks) and resolves hour/minute
     * arithmetically within a matching day, so even sparse expressions
     * ("once a year") resolve in at most ~366 iterations per year.
     */
    public function nextRunDate(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        // Truncate to the minute, then step past `$after` so the result
        // is strictly in the future.
        $t = $after->setTime((int) $after->format('G'), (int) $after->format('i'))
            ->modify('+1 minute');

        for ($i = 0; $i < self::MAX_DAY_SCAN; $i++) {
            if (! isset($this->months[(int) $t->format('n')])) {
                // Jump to the first day of the next month, midnight.
                $t = $t->modify('first day of next month')->setTime(0, 0);
                continue;
            }

            if (! $this->dayMatches($t)) {
                $t = $t->modify('+1 day')->setTime(0, 0);
                continue;
            }

            $resolved = $this->resolveTimeWithinDay($t);
            if ($resolved !== null) {
                return $resolved;
            }

            // No matching hour:minute left today — roll to tomorrow.
            $t = $t->modify('+1 day')->setTime(0, 0);
        }

        return null;
    }

    /**
     * Standard cron OR rule for the two day fields. See class docblock.
     */
    private function dayMatches(\DateTimeImmutable $moment): bool
    {
        $domMatch = isset($this->days[(int) $moment->format('j')]);
        $dowMatch = isset($this->weekdays[(int) $moment->format('w')]);

        if ($this->dayRestricted && $this->weekdayRestricted) {
            return $domMatch || $dowMatch;
        }
        if ($this->dayRestricted) {
            return $domMatch;
        }
        if ($this->weekdayRestricted) {
            return $dowMatch;
        }

        return true;
    }

    /**
     * Find the earliest matching hour:minute at or after `$t` on `$t`'s
     * own day, or null when every remaining slot today has passed.
     */
    private function resolveTimeWithinDay(\DateTimeImmutable $t): ?\DateTimeImmutable
    {
        $startHour = (int) $t->format('G');
        $startMinute = (int) $t->format('i');

        for ($hour = $startHour; $hour <= 23; $hour++) {
            if (! isset($this->hours[$hour])) {
                continue;
            }

            $minuteFloor = $hour === $startHour ? $startMinute : 0;
            for ($minute = $minuteFloor; $minute <= 59; $minute++) {
                if (isset($this->minutes[$minute])) {
                    return $t->setTime($hour, $minute);
                }
            }
        }

        return null;
    }

    /**
     * Parse one cron field into a value set.
     *
     * @param array<string, int> $names 3-letter name aliases for this field
     * @return array<int, true>
     */
    private static function parseField(string $field, int $min, int $max, array $names, string $label): array
    {
        if (trim($field) === '') {
            throw new \InvalidArgumentException("Empty {$label} field in cron expression.");
        }

        $values = [];

        foreach (explode(',', $field) as $term) {
            foreach (self::parseTerm($term, $min, $max, $names, $label) as $value) {
                // Both 0 and 7 mean Sunday; normalize to 0 so matching
                // against date('w') (0-6) is uniform.
                if ($label === 'day-of-week' && $value === 7) {
                    $value = 0;
                }
                $values[$value] = true;
            }
        }

        return $values;
    }

    /**
     * Parse a single comma-separated term: `*`, `*\/step`, `a`, `a-b`,
     * `a-b/step`, or `a/step` (a through field-max, stepped).
     *
     * @param array<string, int> $names
     * @return list<int>
     */
    private static function parseTerm(string $term, int $min, int $max, array $names, string $label): array
    {
        $step = 1;
        if (str_contains($term, '/')) {
            [$term, $stepStr] = explode('/', $term, 2);
            if (! ctype_digit($stepStr) || (int) $stepStr < 1) {
                throw new \InvalidArgumentException("Invalid step \"{$stepStr}\" in {$label} field.");
            }
            $step = (int) $stepStr;
        }

        if ($term === '*') {
            $lo = $min;
            $hi = $max;
        } elseif (str_contains($term, '-')) {
            [$loStr, $hiStr] = explode('-', $term, 2);
            $lo = self::parseValue($loStr, $names, $label);
            $hi = self::parseValue($hiStr, $names, $label);
            if ($lo > $hi) {
                throw new \InvalidArgumentException("Inverted range \"{$term}\" in {$label} field.");
            }
        } else {
            $lo = self::parseValue($term, $names, $label);
            // A bare value with a step (`5/10`) means "from 5 to max,
            // every 10"; without a step it's just the single value.
            $hi = $step > 1 ? $max : $lo;
        }

        if ($lo < $min || $hi > $max) {
            throw new \InvalidArgumentException(
                "Value out of range in {$label} field: \"{$term}\" must be within {$min}-{$max}.",
            );
        }

        $values = [];
        for ($v = $lo; $v <= $hi; $v += $step) {
            $values[] = $v;
        }

        return $values;
    }

    /**
     * @param array<string, int> $names
     */
    private static function parseValue(string $value, array $names, string $label): int
    {
        $upper = strtoupper(trim($value));

        if (isset($names[$upper])) {
            return $names[$upper];
        }

        if (! ctype_digit($upper)) {
            throw new \InvalidArgumentException("Invalid value \"{$value}\" in {$label} field.");
        }

        return (int) $upper;
    }
}
