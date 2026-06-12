<?php

namespace markhuot\craftai\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\models\ScheduledAgent;
use markhuot\craftai\Plugin;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\ScheduledRunRecord;
use markhuot\craftai\records\SessionRecord;

/**
 * Evaluates scheduled agents and fires the ones whose slot has come due.
 *
 * The time-triggered sibling of {@see AutomationDispatcher}. Invoked
 * once a minute by `php craft craft-ai/schedule/run` (host crontab); each
 * pass walks the scheduled agents in plugin settings, advances their
 * per-agent state in `craftai_scheduled_runs`, and pushes an
 * {@see AgentJob} for every slot that fires — so the agent loop itself
 * still runs asynchronously on the queue worker, identical to the
 * event-driven path.
 *
 * **State model**: each agent keeps exactly one `pending` row holding
 * its precomputed next slot. On evaluation, every slot between that row
 * and "now" is processed: the *newest* due slot fires if it's within
 * {@see GRACE_WINDOW_SECONDS} of now; every other due slot is logged as
 * `missed`. That implements both halves of the missed-run policy —
 * skipped slots never stack into a thundering herd after downtime, and
 * each one leaves a visible log row so an editor can see the weekly
 * post didn't go out.
 *
 * **Run-as identity**: slots fire as the agent's stored `userId` (the
 * creating admin). A missing user produces an `error` row instead of a
 * fire — loudly broken beats silently running with no identity.
 *
 * **Timezones**: schedules are wall-clock in Craft's system timezone;
 * `scheduledFor` is stored UTC like every other Craft datetime column.
 *
 * Re-entrancy is guarded with a mutex so overlapping cron invocations
 * (a slow run bleeding into the next minute) can't double-fire a slot.
 */
class ScheduleDispatcher
{
    /**
     * How stale a due slot can be and still fire, in seconds. The
     * console command is meant to run every minute; 15 minutes absorbs
     * a slow queue, a deploy window, or a wedged worker without
     * resurrecting genuinely old slots. Anything older logs as `missed`.
     */
    public const GRACE_WINDOW_SECONDS = 900;

    /**
     * Upper bound on slots processed per agent per pass. A minutely
     * custom cron that was down for a month would otherwise generate
     * ~43k missed rows in one go; past this cap the remainder collapses
     * into the final slot's `detail` instead.
     */
    private const MAX_SLOTS_PER_PASS = 1000;

    private const MUTEX_NAME = 'craft-ai:schedule:run';

    /**
     * Evaluate every scheduled agent against `$now` (defaults to the
     * current moment in Craft's system timezone — injectable for tests).
     *
     * @return array{checked: int, fired: int, missed: int, errors: int, skipped: bool}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $summary = ['checked' => 0, 'fired' => 0, 'missed' => 0, 'errors' => 0, 'skipped' => false];

        $mutex = Craft::$app->getMutex();
        if (! $mutex->acquire(self::MUTEX_NAME)) {
            // Another invocation is mid-pass (slow run bleeding into the
            // next cron minute). Its pass will process anything due, so
            // bail rather than wait and double-evaluate.
            $summary['skipped'] = true;

            return $summary;
        }

        try {
            $now ??= new \DateTimeImmutable('now', new \DateTimeZone(Craft::$app->getTimeZone()));

            foreach ($this->loadScheduledAgents() as $agent) {
                if (! $agent->enabled) {
                    // Drop any stale pending row so the disabled window
                    // doesn't read as downtime (and log spurious misses)
                    // when the agent is re-enabled later.
                    ScheduledRunRecord::deleteAll([
                        'scheduledAgentUid' => $agent->uid,
                        'status' => ScheduledRunRecord::STATUS_PENDING,
                    ]);
                    continue;
                }

                $summary['checked']++;
                $result = $this->processAgent($agent, $now);
                $summary['fired'] += $result['fired'];
                $summary['missed'] += $result['missed'];
                $summary['errors'] += $result['errors'];
            }
        } finally {
            $mutex->release(self::MUTEX_NAME);
        }

        return $summary;
    }

    /**
     * @return list<ScheduledAgent>
     */
    private function loadScheduledAgents(): array
    {
        try {
            $plugin = Plugin::getInstance();
        } catch (\Throwable) {
            return [];
        }

        $settings = $plugin->getSettings();
        if (! $settings instanceof \markhuot\craftai\models\Settings) {
            return [];
        }

        return $settings->getScheduledAgents();
    }

    /**
     * @return array{fired: int, missed: int, errors: int}
     */
    private function processAgent(ScheduledAgent $agent, \DateTimeImmutable $now): array
    {
        $result = ['fired' => 0, 'missed' => 0, 'errors' => 0];

        $pending = $this->pendingRecord($agent, $now);
        if ($pending === null) {
            // Schedule exhausted: a one-time agent that already ran (or
            // whose slot was already adjudicated), or an unsatisfiable
            // custom cron. Nothing to do until the definition changes.
            return $result;
        }

        $slot = $this->slotMoment($pending, $now);
        if ($slot === null || $slot > $now) {
            return $result;
        }

        // Collect every slot from the pending one through "now". The
        // newest is the only fire candidate; older ones were missed.
        $slots = [$slot];
        $truncated = false;
        $cursor = $agent->nextRunAfter($slot);
        while ($cursor !== null && $cursor <= $now) {
            $slots[] = $cursor;
            if (count($slots) >= self::MAX_SLOTS_PER_PASS) {
                $truncated = true;
                break;
            }
            $cursor = $agent->nextRunAfter($cursor);
        }

        $newest = $slots[count($slots) - 1];
        $withinGrace = ($now->getTimestamp() - $newest->getTimestamp()) <= self::GRACE_WINDOW_SECONDS;

        foreach ($slots as $i => $dueSlot) {
            $isNewest = $i === count($slots) - 1;

            // The first slot reuses the pending row (so the row's history
            // is continuous); later slots get fresh rows.
            $record = $i === 0 ? $pending : $this->newRunRecord($agent, $dueSlot);

            if ($isNewest && $withinGrace) {
                $result[$this->fire($agent, $dueSlot, $record) ? 'fired' : 'errors']++;
            } else {
                $record->status = ScheduledRunRecord::STATUS_MISSED;
                $record->detail = sprintf(
                    'Slot passed outside the %d-minute grace window without a schedule run.',
                    self::GRACE_WINDOW_SECONDS / 60,
                );
                if ($truncated && $isNewest) {
                    $record->detail = sprintf(
                        'Downtime exceeded %d slots; the remaining misses were collapsed into this row.',
                        self::MAX_SLOTS_PER_PASS,
                    );
                }
                $record->save();
                $result['missed']++;
            }
        }

        // Stage the next pending slot for repeating schedules. One-time
        // schedules are exhausted at this point — nextRunAfter() returns
        // null and no pending row is recreated.
        $next = $agent->nextRunAfter($now);
        if ($next !== null) {
            $this->newRunRecord($agent, $next)->save();
        }

        return $result;
    }

    /**
     * Resolve the agent's `pending` row, creating one when absent.
     *
     * Creation normally seeds the *next* occurrence after now — a brand
     * new schedule has nothing "missed" before it existed. The one
     * exception: a never-processed one-time agent whose runDate is
     * already past still deserves adjudication (fire within grace,
     * missed beyond it), so its pending row is seeded at the runDate
     * itself rather than silently never materializing.
     */
    private function pendingRecord(ScheduledAgent $agent, \DateTimeImmutable $now): ?ScheduledRunRecord
    {
        $pending = ScheduledRunRecord::findOne([
            'scheduledAgentUid' => $agent->uid,
            'status' => ScheduledRunRecord::STATUS_PENDING,
        ]);

        if ($pending !== null) {
            return $pending;
        }

        $slot = $agent->nextRunAfter($now);

        if ($slot === null && $agent->frequency === ScheduledAgent::FREQUENCY_ONCE) {
            $hasHistory = ScheduledRunRecord::find()
                ->where(['scheduledAgentUid' => $agent->uid])
                ->exists();
            if (! $hasHistory) {
                $slot = $agent->runDateMoment(new \DateTimeZone(Craft::$app->getTimeZone()));
            }
        }

        if ($slot === null) {
            return null;
        }

        $record = $this->newRunRecord($agent, $slot);
        $record->save();

        return $record;
    }

    private function newRunRecord(ScheduledAgent $agent, \DateTimeImmutable $slot): ScheduledRunRecord
    {
        $record = new ScheduledRunRecord();
        $record->scheduledAgentUid = $agent->uid;
        // prepareDateForDb only returns null for null input; the throw is
        // a type-level guard, not a reachable path.
        $record->scheduledFor = Db::prepareDateForDb($slot)
            ?? throw new \UnexpectedValueException('Failed to serialize the slot moment for storage.');
        $record->status = ScheduledRunRecord::STATUS_PENDING;

        return $record;
    }

    /**
     * Read a record's `scheduledFor` (stored UTC) back as an immutable
     * moment in `$now`'s timezone so slot arithmetic stays wall-clock.
     */
    private function slotMoment(ScheduledRunRecord $record, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $parsed = DateTimeHelper::toDateTime($record->scheduledFor);
        if ($parsed === false) {
            return null;
        }

        return \DateTimeImmutable::createFromMutable($parsed)->setTimezone($now->getTimezone());
    }

    /**
     * Fire one slot: create the session, seed prompt + schedule context,
     * push the queue job, and move the run row to `queued`. Returns false
     * (and writes an `error` row) when the run-as user is gone — the
     * schedule itself stays live, so restoring the user (or re-saving the
     * agent under a new owner) resumes runs without reconfiguration.
     */
    private function fire(ScheduledAgent $agent, \DateTimeImmutable $slot, ScheduledRunRecord $record): bool
    {
        $user = $agent->userId !== null ? Craft::$app->getUsers()->getUserById($agent->userId) : null;
        if ($user === null) {
            $record->status = ScheduledRunRecord::STATUS_ERROR;
            $record->detail = $agent->userId === null
                ? 'No run-as user is stored on this scheduled agent. Re-save it in the control panel to claim ownership.'
                : "Run-as user #{$agent->userId} no longer exists. Re-save the scheduled agent to transfer ownership.";
            $record->save();

            return false;
        }

        $sessionId = StringHelper::UUID();

        $session = new SessionRecord();
        $session->id = $sessionId;
        $session->active = false;
        $session->userId = $agent->userId;
        $session->title = $this->buildTitle($agent, $slot);
        $session->save();

        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);
        $loop->appendUserMessage($sessionId, $agent->prompt);
        $loop->appendSystemContext($sessionId, $this->buildScheduleContext($agent, $slot));

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userId' => $agent->userId,
        ]));

        $record->status = ScheduledRunRecord::STATUS_QUEUED;
        $record->sessionId = $sessionId;
        $record->save();

        return true;
    }

    private function buildTitle(ScheduledAgent $agent, \DateTimeImmutable $slot): string
    {
        $label = $agent->name !== '' ? $agent->name : 'Scheduled agent';
        $title = "{$label}: {$slot->format('M j, Y g:i A')}";
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }

        return $title;
    }

    private function buildScheduleContext(ScheduledAgent $agent, \DateTimeImmutable $slot): string
    {
        $label = $agent->name !== '' ? $agent->name : '(unnamed)';
        $schedule = $agent->describeSchedule();
        $moment = $slot->format('Y-m-d H:i T');

        return <<<NOTE
            [Scheduled agent "{$label}" fired for its "{$schedule}" slot at {$moment}]
            You are running unattended on a schedule — no human is watching
            this session live, so don't ask clarifying questions; make
            reasonable editorial decisions and complete the task using the
            available tools. The user prompt above is the task. If the task
            involves publishing content, prefer creating it as a draft via
            `upsert_draft` unless the prompt explicitly says to publish.
            NOTE;
    }
}
