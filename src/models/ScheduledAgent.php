<?php

namespace markhuot\craftai\models;

use craft\base\Model;
use craft\helpers\StringHelper;
use markhuot\craftai\scheduling\CronExpression;

/**
 * A single user-defined scheduled agent.
 *
 * The time-triggered counterpart to {@see Automation}: instead of
 * listening for a Craft event, each scheduled agent fires its prompt on a
 * calendar — one time (`once`) or repeating (`hourly` / `daily` /
 * `weekly` / `monthly` / raw `custom` cron). The friendly frequencies are
 * stored as their component parts (time, day-of-week, …) so the edit UI
 * can round-trip them, and {@see toCronExpression()} derives the cron
 * shape the dispatcher actually evaluates.
 *
 * Definitions live in the plugin's settings alongside automations and
 * commands (project-config round-trip and all); runtime state — the next
 * pending slot and the run history — lives in the
 * `craftai_scheduled_runs` table via
 * {@see \markhuot\craftai\records\ScheduledRunRecord}, because "when did
 * this last fire" is per-environment data that must not sync through
 * project config.
 *
 * `userId` is the creating admin, captured at save time. Scheduled runs
 * execute as that user (tool permission checks flow from the identity
 * {@see \markhuot\craftai\queue\AgentJob} restores), so attribution on
 * created entries is explicit. If the user is later deleted the
 * dispatcher logs an `error` run instead of firing — loudly broken
 * beats silently running as no one. Note user ids are environment-
 * specific: when these settings sync to another environment via project
 * config, re-save the agent there if the creator's id differs.
 *
 * All schedule times are wall-clock in Craft's system timezone.
 */
class ScheduledAgent extends Model
{
    public const FREQUENCY_ONCE = 'once';
    public const FREQUENCY_HOURLY = 'hourly';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_CUSTOM = 'custom';

    /**
     * Canonical frequency list with human labels for the settings
     * dropdown. Keep the keys aligned with the FREQUENCY_* constants.
     *
     * @return array<string, string>
     */
    public static function frequencyChoices(): array
    {
        return [
            self::FREQUENCY_ONCE => 'Once (does not repeat)',
            self::FREQUENCY_HOURLY => 'Hourly',
            self::FREQUENCY_DAILY => 'Daily',
            self::FREQUENCY_WEEKLY => 'Weekly',
            self::FREQUENCY_MONTHLY => 'Monthly',
            self::FREQUENCY_CUSTOM => 'Custom (cron expression)',
        ];
    }

    /**
     * Cron weekday numbering: 0 = Sunday … 6 = Saturday.
     *
     * @return array<int, string>
     */
    public static function dayOfWeekChoices(): array
    {
        return [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
    }

    /**
     * Stable identifier. Persisted so the settings UI can link to the
     * dedicated edit screen and the runs table can reference its agent.
     * Generated on first save if the caller didn't supply one.
     */
    public string $uid = '';

    /** Optional human label. Surfaces in session titles and the runs log. */
    public string $name = '';

    public string $prompt = '';

    public string $frequency = self::FREQUENCY_WEEKLY;

    /** Minute of the hour (0-59). Only consulted for `hourly`. */
    public int $minute = 0;

    /** Wall-clock `HH:MM` (24h). Consulted for `daily`, `weekly`, and `monthly`. */
    public string $time = '09:00';

    /** Cron weekday (0 = Sunday … 6 = Saturday). Only consulted for `weekly`. */
    public int $dayOfWeek = 1;

    /**
     * Day of the month (1-31). Only consulted for `monthly`. Standard
     * cron semantics: a 29-31 schedule simply skips months without that
     * day.
     */
    public int $dayOfMonth = 1;

    /**
     * One-time run moment, `Y-m-d\TH:i` (the `datetime-local` input
     * shape), in Craft's system timezone. Only consulted for `once`.
     */
    public string $runDate = '';

    /** Raw 5-field cron expression. Only consulted for `custom`. */
    public string $cronExpression = '';

    /** Creating admin's user id — the identity scheduled runs execute as. */
    public ?int $userId = null;

    public bool $enabled = true;

    public function init(): void
    {
        parent::init();

        if ($this->uid === '') {
            $this->uid = StringHelper::UUID();
        }
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['prompt', 'frequency'], 'required'],
            [['frequency'], 'in', 'range' => array_keys(self::frequencyChoices())],
            [['name'], 'string', 'max' => 255],
            [['prompt'], 'string', 'max' => 4000],
            [['minute'], 'integer', 'min' => 0, 'max' => 59],
            [['dayOfWeek'], 'integer', 'min' => 0, 'max' => 6],
            [['dayOfMonth'], 'integer', 'min' => 1, 'max' => 31],
            [['userId'], 'integer'],
            [['enabled'], 'boolean'],
            // skipOnEmpty=false: these inline validators implement the
            // per-frequency "required" semantics themselves (an empty
            // runDate is only an error when frequency=once), so they must
            // run even when the attribute is empty — Yii's default would
            // silently skip them and let an incomplete row validate.
            [['time'], 'validateTime', 'skipOnEmpty' => false],
            [['runDate'], 'validateRunDate', 'skipOnEmpty' => false],
            [['cronExpression'], 'validateCronExpression', 'skipOnEmpty' => false],
        ];
    }

    public function validateTime(string $attribute): void
    {
        if (! in_array($this->frequency, [self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY], true)) {
            return;
        }

        if (self::parseTimeOfDay($this->time) === null) {
            $this->addError($attribute, 'Time must be in HH:MM (24-hour) format.');
        }
    }

    public function validateRunDate(string $attribute): void
    {
        if ($this->frequency !== self::FREQUENCY_ONCE) {
            return;
        }

        if ($this->runDate === '') {
            $this->addError($attribute, 'A date and time is required for a one-time schedule.');
            return;
        }

        if ($this->runDateMoment(new \DateTimeZone('UTC')) === null) {
            $this->addError($attribute, 'Run date must be a valid date and time (YYYY-MM-DDTHH:MM).');
        }
    }

    public function validateCronExpression(string $attribute): void
    {
        if ($this->frequency !== self::FREQUENCY_CUSTOM) {
            return;
        }

        if (trim($this->cronExpression) === '') {
            $this->addError($attribute, 'A cron expression is required for a custom schedule.');
            return;
        }

        if (! CronExpression::isValid($this->cronExpression)) {
            $this->addError($attribute, 'That isn’t a valid 5-field cron expression (minute hour day month weekday).');
        }
    }

    /**
     * Derive the cron expression the dispatcher evaluates. Null for
     * `once` — a one-time schedule has no recurrence to express; the
     * dispatcher reads {@see runDateMoment()} instead.
     */
    public function toCronExpression(): ?string
    {
        return match ($this->frequency) {
            self::FREQUENCY_ONCE => null,
            self::FREQUENCY_HOURLY => "{$this->minute} * * * *",
            self::FREQUENCY_DAILY => $this->timeAsCron().' * * *',
            self::FREQUENCY_WEEKLY => $this->timeAsCron()." * * {$this->dayOfWeek}",
            self::FREQUENCY_MONTHLY => $this->timeAsCron()." {$this->dayOfMonth} * *",
            self::FREQUENCY_CUSTOM => trim($this->cronExpression),
            default => null,
        };
    }

    /**
     * The next moment this schedule should fire strictly after `$after`,
     * in `$after`'s timezone. Null when the schedule is exhausted (a
     * one-time run already past) or unresolvable (invalid custom cron —
     * validation should have caught it, but hand-edited config exists).
     */
    public function nextRunAfter(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        if ($this->frequency === self::FREQUENCY_ONCE) {
            $moment = $this->runDateMoment($after->getTimezone());

            return $moment !== null && $moment > $after ? $moment : null;
        }

        $expression = $this->toCronExpression();
        if ($expression === null) {
            return null;
        }

        try {
            return CronExpression::parse($expression)->nextRunDate($after);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Parse `runDate` into a concrete moment in the given timezone, or
     * null when it's absent/malformed. Accepts both the `datetime-local`
     * `T` separator and a plain space (hand-edited config).
     */
    public function runDateMoment(\DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $raw = str_replace(' ', 'T', trim($this->runDate));

        $moment = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $timezone);

        return $moment === false ? null : $moment;
    }

    /**
     * Human-readable schedule summary for the settings list and the
     * runs log ("Weekly on Monday at 9:00 AM").
     */
    public function describeSchedule(): string
    {
        return match ($this->frequency) {
            self::FREQUENCY_ONCE => 'Once at '.$this->describeRunDate(),
            self::FREQUENCY_HOURLY => sprintf('Hourly at :%02d', $this->minute),
            self::FREQUENCY_DAILY => 'Daily at '.$this->describeTime(),
            self::FREQUENCY_WEEKLY => sprintf(
                'Weekly on %s at %s',
                self::dayOfWeekChoices()[$this->dayOfWeek] ?? (string) $this->dayOfWeek,
                $this->describeTime(),
            ),
            self::FREQUENCY_MONTHLY => sprintf('Monthly on day %d at %s', $this->dayOfMonth, $this->describeTime()),
            self::FREQUENCY_CUSTOM => 'Cron: '.trim($this->cronExpression),
            default => $this->frequency,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'uid' => $this->uid,
            'name' => $this->name,
            'prompt' => $this->prompt,
            'frequency' => $this->frequency,
            'minute' => $this->minute,
            'time' => $this->time,
            'dayOfWeek' => $this->dayOfWeek,
            'dayOfMonth' => $this->dayOfMonth,
            'runDate' => $this->runDate,
            'cronExpression' => $this->cronExpression,
            'userId' => $this->userId,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Inflate from a raw associative array, tolerating missing keys so
     * older settings rows and partial POSTs both work.
     *
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $agent = new self();
        $agent->uid = is_string($data['uid'] ?? null) && $data['uid'] !== '' ? $data['uid'] : StringHelper::UUID();
        $agent->name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $agent->prompt = is_string($data['prompt'] ?? null) ? $data['prompt'] : '';
        $agent->frequency = is_string($data['frequency'] ?? null) ? $data['frequency'] : self::FREQUENCY_WEEKLY;
        $agent->minute = is_numeric($data['minute'] ?? null) ? (int) $data['minute'] : 0;
        $agent->time = is_string($data['time'] ?? null) && $data['time'] !== '' ? $data['time'] : '09:00';
        $agent->dayOfWeek = is_numeric($data['dayOfWeek'] ?? null) ? (int) $data['dayOfWeek'] : 1;
        $agent->dayOfMonth = is_numeric($data['dayOfMonth'] ?? null) ? (int) $data['dayOfMonth'] : 1;
        $agent->runDate = is_string($data['runDate'] ?? null) ? trim($data['runDate']) : '';
        $agent->cronExpression = is_string($data['cronExpression'] ?? null) ? trim($data['cronExpression']) : '';
        $agent->userId = is_numeric($data['userId'] ?? null) ? (int) $data['userId'] : null;
        // Craft form posts deliver booleans as "1"/"0" or "on"/"" depending
        // on the input. Normalize to a real bool — same as Automation.
        $rawEnabled = $data['enabled'] ?? true;
        $agent->enabled = filter_var($rawEnabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        return $agent;
    }

    /**
     * `HH:MM` → `[hour, minute]`, or null when malformed.
     *
     * @return array{int, int}|null
     */
    private static function parseTimeOfDay(string $time): ?array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return [$hour, $minute];
    }

    /** `HH:MM` → the leading `minute hour` cron fields. */
    private function timeAsCron(): string
    {
        [$hour, $minute] = self::parseTimeOfDay($this->time) ?? [9, 0];

        return "{$minute} {$hour}";
    }

    /** `HH:MM` → `9:00 AM` for human-readable summaries. */
    private function describeTime(): string
    {
        $parsed = self::parseTimeOfDay($this->time);
        if ($parsed === null) {
            return $this->time;
        }

        [$hour, $minute] = $parsed;
        $period = $hour >= 12 ? 'PM' : 'AM';
        $display = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%d:%02d %s', $display, $minute, $period);
    }

    private function describeRunDate(): string
    {
        $moment = $this->runDateMoment(new \DateTimeZone('UTC'));

        return $moment === null ? $this->runDate : $moment->format('M j, Y g:i A');
    }
}
