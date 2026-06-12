<?php

namespace markhuot\craftai\records;

use craft\db\ActiveRecord;

/**
 * One slot on a scheduled agent's calendar — both the runtime state and
 * the audit log for {@see \markhuot\craftai\models\ScheduledAgent}.
 *
 * Each agent has at most one `pending` row: the next slot the dispatcher
 * is waiting on (`scheduledFor` is precomputed from the schedule, so the
 * settings UI reads "next run" straight off it, and a downtime window is
 * detectable as a pending row left in the past). When the slot comes due
 * the row transitions to a terminal status and — for repeating schedules
 * — a fresh `pending` row is inserted for the next occurrence.
 *
 * Statuses:
 *  - `pending`: the next slot, waiting for its moment.
 *  - `queued`:  fired — a session was created and an AgentJob pushed.
 *               `sessionId` links to the conversation.
 *  - `missed`:  the slot passed by more than the dispatcher's grace
 *               window (cron/site was down) and was deliberately not
 *               fired. Visible in the runs log so an editor knows the
 *               weekly post didn't happen — the "skip, but log it"
 *               missed-run policy.
 *  - `error`:   the slot was due but couldn't fire (e.g. the run-as
 *               user no longer exists). `detail` says why.
 *
 * Rows live outside project config on purpose: when a run happened is
 * per-environment state. Definitions sync via plugin settings; this
 * table does not.
 *
 * @property int $id
 * @property string $scheduledAgentUid UID of the owning ScheduledAgent settings row
 * @property string $scheduledFor The slot's wall-clock moment (stored UTC)
 * @property string $status pending|queued|missed|error
 * @property string|null $sessionId Session created when the slot fired
 * @property string|null $detail Human-readable context (e.g. error reason)
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ScheduledRunRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_MISSED = 'missed';
    public const STATUS_ERROR = 'error';

    public static function tableName(): string
    {
        return '{{%craftai_scheduled_runs}}';
    }
}
