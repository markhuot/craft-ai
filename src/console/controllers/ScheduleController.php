<?php

namespace markhuot\craftai\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\DateTimeHelper;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use markhuot\craftai\records\ScheduledRunRecord;
use markhuot\craftai\services\ScheduleDispatcher;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console entrypoint for scheduled agents.
 *
 * `php craft craft-ai/schedule/run` is the tick the whole feature hangs
 * off — Craft has no built-in scheduler, so the host's crontab calls it
 * every minute:
 *
 *     * * * * * /usr/bin/php /path/to/craft craft-ai/schedule/run
 *
 * Each invocation is cheap when nothing is due (one settings read + one
 * indexed query per agent), and a mutex inside the dispatcher makes
 * overlapping invocations safe. Firing pushes a queue job rather than
 * running the agent inline, so a long agent run never blocks the
 * schedule tick — make sure a queue worker is running too.
 *
 * `php craft craft-ai/schedule/list` prints each agent with its next
 * pending slot and most recent outcome, handy for verifying the crontab
 * wiring without watching the CP.
 */
class ScheduleController extends Controller
{
    /** @var string */
    public $defaultAction = 'run';

    /**
     * Evaluate scheduled agents and fire any whose slot has come due.
     */
    public function actionRun(): int
    {
        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = Craft::$container->get(ScheduleDispatcher::class);
        $summary = $dispatcher->run();

        if ($summary['skipped']) {
            $this->stdout("Another schedule run is already in progress; skipped this pass.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Checked %d scheduled agent(s): %d fired, %d missed, %d error(s).\n",
            $summary['checked'],
            $summary['fired'],
            $summary['missed'],
            $summary['errors'],
        ), $summary['errors'] > 0 ? Console::FG_RED : Console::FG_GREEN);

        return $summary['errors'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * List scheduled agents with their next pending slot and last outcome.
     */
    public function actionList(): int
    {
        $settings = Plugin::getInstance()->getSettings();
        if (! $settings instanceof Settings) {
            $this->stderr("Couldn't load craft-ai settings.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $agents = $settings->getScheduledAgents();
        if ($agents === []) {
            $this->stdout("No scheduled agents configured.\n");

            return ExitCode::OK;
        }

        foreach ($agents as $agent) {
            $name = $agent->name !== '' ? $agent->name : '(unnamed)';
            $state = $agent->enabled ? 'enabled' : 'disabled';

            $this->stdout("{$name} ", Console::BOLD);
            $this->stdout("[{$state}] {$agent->describeSchedule()}\n");

            $pending = ScheduledRunRecord::findOne([
                'scheduledAgentUid' => $agent->uid,
                'status' => ScheduledRunRecord::STATUS_PENDING,
            ]);
            $this->stdout('  Next run: '.($pending !== null ? $this->formatDbDate($pending->scheduledFor) : '—')."\n");

            $last = ScheduledRunRecord::find()
                ->where(['scheduledAgentUid' => $agent->uid])
                ->andWhere(['!=', 'status', ScheduledRunRecord::STATUS_PENDING])
                ->orderBy(['scheduledFor' => SORT_DESC])
                ->one();
            if ($last instanceof ScheduledRunRecord) {
                $detail = $last->detail !== null && $last->detail !== '' ? " ({$last->detail})" : '';
                $this->stdout("  Last run: {$this->formatDbDate($last->scheduledFor)} — {$last->status}{$detail}\n");
            } else {
                $this->stdout("  Last run: —\n");
            }
        }

        return ExitCode::OK;
    }

    /** Render a stored-UTC datetime in the system timezone for display. */
    private function formatDbDate(string $value): string
    {
        $parsed = DateTimeHelper::toDateTime($value);

        return $parsed === false ? $value : $parsed->format('Y-m-d H:i T');
    }
}
