<?php

use Craft;
use markhuot\craftai\agent\providers\LlmProvider;
use markhuot\craftai\agent\providers\ProviderResponse;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use markhuot\craftai\records\MessageRecord;
use markhuot\craftai\records\ScheduledRunRecord;
use markhuot\craftai\records\SessionRecord;
use markhuot\craftai\services\ScheduleDispatcher;

/**
 * Inert LLM provider for the schedule dispatcher tests. Bound in place of
 * the real provider singleton so the container can construct AgentLoop
 * (the dispatcher resolves it at fire time) without a configured API key —
 * these tests assert on the records the dispatcher writes before the job
 * is queued, never on the loop's send path.
 */
final class ScheduleDispatcherTestNoopProvider implements LlmProvider
{
    public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
    {
        return new ProviderResponse(id: 'noop', content: [], stopReason: 'end_turn');
    }
}

beforeEach(function () {
    Craft::$container->setSingleton(LlmProvider::class, fn () => new ScheduleDispatcherTestNoopProvider());
});

function applyScheduledAgentSettings(array $agents): Settings
{
    $settings = new Settings();
    $settings->setScheduledAgents($agents);

    // Inject the settings into the plugin instance so the dispatcher sees
    // them via Plugin::getInstance()->getSettings(). The base Plugin
    // stores its lazily-created model in $_settings on the parent class,
    // so we reflect against the base class to find the property.
    $plugin = Plugin::getInstance();
    $prop = (new \ReflectionClass(\craft\base\Plugin::class))->getProperty('_settings');
    $prop->setAccessible(true);
    $prop->setValue($plugin, $settings);

    return $settings;
}

function runScheduleDispatcher(string $now): array
{
    /** @var ScheduleDispatcher $dispatcher */
    $dispatcher = Craft::$container->get(ScheduleDispatcher::class);

    return $dispatcher->run(new \DateTimeImmutable($now, new \DateTimeZone(Craft::$app->getTimeZone())));
}

function scheduleRunsByStatus(string $uid, string $status): array
{
    return ScheduledRunRecord::find()
        ->where(['scheduledAgentUid' => $uid, 'status' => $status])
        ->orderBy(['scheduledFor' => SORT_ASC])
        ->all();
}

/**
 * Convert a wall-clock moment (system timezone — the same shape the
 * tests pass to the dispatcher) into the UTC string the DB stores, so
 * assertions on `scheduledFor` survive any host timezone.
 */
function expectedDbSlot(string $wallClock): string
{
    $moment = new \DateTimeImmutable($wallClock, new \DateTimeZone(Craft::$app->getTimeZone()));

    return (string) \craft\helpers\Db::prepareDateForDb($moment);
}

it('stages a pending slot on first evaluation without firing', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'Hourly check', 'prompt' => 'Check things.', 'frequency' => 'hourly', 'minute' => 0, 'userId' => 1],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    $summary = runScheduleDispatcher('2026-06-04 09:30:00');

    expect($summary['checked'])->toBe(1);
    expect($summary['fired'])->toBe(0);
    expect(SessionRecord::find()->all())->toHaveCount(0);

    $pending = scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING);
    expect($pending)->toHaveCount(1);
    expect($pending[0]->scheduledFor)->toBe(expectedDbSlot('2026-06-04 10:00:00'));
});

it('fires a due slot within the grace window', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'Hourly check', 'prompt' => 'Check things.', 'frequency' => 'hourly', 'minute' => 0, 'userId' => 1],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    runScheduleDispatcher('2026-06-04 09:30:00'); // stages 10:00
    $summary = runScheduleDispatcher('2026-06-04 10:02:00'); // 2 min late: within grace

    expect($summary['fired'])->toBe(1);
    expect($summary['missed'])->toBe(0);

    $sessions = SessionRecord::find()->all();
    expect($sessions)->toHaveCount(1);
    expect($sessions[0]->title)->toContain('Hourly check');
    expect($sessions[0]->userId)->toBe(1);

    $userMsg = MessageRecord::find()
        ->where(['sessionId' => $sessions[0]->id, 'role' => 'user'])
        ->one();
    expect($userMsg)->not->toBeNull();
    expect($userMsg->content)->toContain('Check things.');

    $sysMsg = MessageRecord::find()
        ->where(['sessionId' => $sessions[0]->id, 'role' => 'system'])
        ->one();
    expect($sysMsg)->not->toBeNull();
    expect($sysMsg->content)->toContain('running unattended');

    $queued = scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_QUEUED);
    expect($queued)->toHaveCount(1);
    expect($queued[0]->sessionId)->toBe($sessions[0]->id);

    // The next slot is staged for the following hour.
    $pending = scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING);
    expect($pending)->toHaveCount(1);
    expect($pending[0]->scheduledFor)->toBe(expectedDbSlot('2026-06-04 11:00:00'));
});

it('logs a slot as missed when it comes due outside the grace window', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'Hourly check', 'prompt' => 'Check things.', 'frequency' => 'hourly', 'minute' => 0, 'userId' => 1],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    runScheduleDispatcher('2026-06-04 09:30:00'); // stages 10:00
    // Cron was down until 12:47 — slots 10:00, 11:00, 12:00 all stale.
    $summary = runScheduleDispatcher('2026-06-04 12:47:00');

    expect($summary['fired'])->toBe(0);
    expect($summary['missed'])->toBe(3);
    expect(SessionRecord::find()->all())->toHaveCount(0);

    $missed = scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_MISSED);
    expect($missed)->toHaveCount(3);
    expect($missed[0]->detail)->toContain('grace window');

    $pending = scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING);
    expect($pending)->toHaveCount(1);
    expect($pending[0]->scheduledFor)->toBe(expectedDbSlot('2026-06-04 13:00:00'));
});

it('fires only the newest due slot after downtime, never stacking runs', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'Hourly check', 'prompt' => 'Check things.', 'frequency' => 'hourly', 'minute' => 0, 'userId' => 1],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    runScheduleDispatcher('2026-06-04 09:30:00'); // stages 10:00
    // Back up at 12:05 — 10:00 and 11:00 are stale, 12:00 is within grace.
    $summary = runScheduleDispatcher('2026-06-04 12:05:00');

    expect($summary['fired'])->toBe(1);
    expect($summary['missed'])->toBe(2);
    expect(SessionRecord::find()->all())->toHaveCount(1);

    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_MISSED))->toHaveCount(2);
    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_QUEUED))->toHaveCount(1);
});

it('runs a one-time schedule exactly once and then exhausts it', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'One shot', 'prompt' => 'Do it once.', 'frequency' => 'once', 'runDate' => '2026-06-10T09:00', 'userId' => 1],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    runScheduleDispatcher('2026-06-09 09:00:00'); // stages the slot
    $summary = runScheduleDispatcher('2026-06-10 09:01:00');

    expect($summary['fired'])->toBe(1);
    expect(SessionRecord::find()->all())->toHaveCount(1);

    // No pending row remains, and later passes do nothing.
    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING))->toHaveCount(0);

    $later = runScheduleDispatcher('2026-06-11 09:01:00');
    expect($later['fired'])->toBe(0);
    expect(SessionRecord::find()->all())->toHaveCount(1);
});

it('fires a freshly created one-time schedule whose moment just passed', function () {
    applyScheduledAgentSettings([
        ['name' => 'Just missed', 'prompt' => 'Do it now.', 'frequency' => 'once', 'runDate' => '2026-06-10T09:00', 'userId' => 1],
    ]);

    // First evaluation happens 5 minutes after the scheduled moment —
    // still within grace, so it fires rather than silently expiring.
    $summary = runScheduleDispatcher('2026-06-10 09:05:00');

    expect($summary['fired'])->toBe(1);
    expect(SessionRecord::find()->all())->toHaveCount(1);
});

it('logs a stale one-time schedule as missed rather than firing it', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'Long gone', 'prompt' => 'Too late.', 'frequency' => 'once', 'runDate' => '2026-06-10T09:00', 'userId' => 1],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    $summary = runScheduleDispatcher('2026-06-10 11:00:00');

    expect($summary['fired'])->toBe(0);
    expect($summary['missed'])->toBe(1);
    expect(SessionRecord::find()->all())->toHaveCount(0);
    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_MISSED))->toHaveCount(1);
    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING))->toHaveCount(0);
});

it('skips disabled agents and clears their staged slot', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'Hourly check', 'prompt' => 'Check things.', 'frequency' => 'hourly', 'minute' => 0, 'userId' => 1],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    runScheduleDispatcher('2026-06-04 09:30:00'); // stages 10:00
    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING))->toHaveCount(1);

    // Disable (same uid so the staged row belongs to this agent).
    $rows = $settings->scheduledAgents;
    $rows[0]['enabled'] = false;
    applyScheduledAgentSettings($rows);

    $summary = runScheduleDispatcher('2026-06-04 12:47:00');

    expect($summary['checked'])->toBe(0);
    expect($summary['missed'])->toBe(0);
    expect(SessionRecord::find()->all())->toHaveCount(0);
    // The stale pending row is gone, so re-enabling won't read the
    // disabled window as downtime.
    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING))->toHaveCount(0);
});

it('writes an error row when the run-as user no longer exists', function () {
    $settings = applyScheduledAgentSettings([
        ['name' => 'Orphaned', 'prompt' => 'Whoami.', 'frequency' => 'hourly', 'minute' => 0, 'userId' => 999999],
    ]);
    $uid = $settings->getScheduledAgents()[0]->uid;

    runScheduleDispatcher('2026-06-04 09:30:00'); // stages 10:00
    $summary = runScheduleDispatcher('2026-06-04 10:02:00');

    expect($summary['fired'])->toBe(0);
    expect($summary['errors'])->toBe(1);
    expect(SessionRecord::find()->all())->toHaveCount(0);

    $errors = scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_ERROR);
    expect($errors)->toHaveCount(1);
    expect($errors[0]->detail)->toContain('no longer exists');

    // The schedule itself stays live — the next slot is staged so fixing
    // ownership resumes runs without reconfiguration.
    expect(scheduleRunsByStatus($uid, ScheduledRunRecord::STATUS_PENDING))->toHaveCount(1);
});

it('evaluates multiple agents independently in one pass', function () {
    applyScheduledAgentSettings([
        ['name' => 'Due now', 'prompt' => 'Fire.', 'frequency' => 'once', 'runDate' => '2026-06-10T09:00', 'userId' => 1],
        ['name' => 'Not yet', 'prompt' => 'Wait.', 'frequency' => 'once', 'runDate' => '2026-06-11T09:00', 'userId' => 1],
    ]);

    $summary = runScheduleDispatcher('2026-06-10 09:01:00');

    expect($summary['checked'])->toBe(2);
    expect($summary['fired'])->toBe(1);

    $sessions = SessionRecord::find()->all();
    expect($sessions)->toHaveCount(1);
    expect($sessions[0]->title)->toContain('Due now');
});
