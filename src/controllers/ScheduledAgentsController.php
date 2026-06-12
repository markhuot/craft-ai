<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\models\ScheduledAgent;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use markhuot\craftai\records\ScheduledRunRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Dedicated CP screens for editing a single scheduled agent.
 *
 * Mirrors {@see AutomationsController}: the plugin settings page lists
 * scheduled agents in a read-only table and links here for actual edits,
 * so the prompt textarea has room to breathe and the schedule picker can
 * swap its fields per frequency without cramming into a table cell. The
 * edit screen also surfaces the agent's recent run history (queued /
 * missed / error rows) so a "did my weekly post go out?" check doesn't
 * require shelling into the console.
 *
 * Saves re-emit the full settings payload (automations + scheduledAgents
 * + commands) because Craft writes plugin settings via a project-config
 * `set()` at `plugins.craft-ai.settings`, which replaces the whole
 * subtree — passing only `scheduledAgents` would wipe the other two.
 *
 * Two pieces of state management beyond the automations pattern:
 *  - `userId` (the run-as identity) is stamped server-side, never taken
 *    from the form: the creating admin on a new agent, preserved on
 *    edits while that user still exists, and re-claimed by the editing
 *    admin when the stored user is gone (matching the dispatcher's
 *    "re-save to transfer ownership" error).
 *  - the agent's `pending` run row is cleared on every save/delete so
 *    the dispatcher recomputes the next slot from the *new* definition
 *    instead of honoring a stale precomputed one.
 */
class ScheduledAgentsController extends Controller
{
    use ResolvesRequestParams;

    /**
     * @param \yii\base\Action<\yii\base\Controller<\yii\base\Module>> $action
     */
    public function beforeAction($action): bool
    {
        if (! parent::beforeAction($action)) {
            return false;
        }

        // Mirror Craft's PluginsController convention: editing plugin
        // settings (and the underlying project-config writes) is admin-only.
        $this->requireAdmin();

        return true;
    }

    /**
     * Render the edit screen for a single scheduled agent. With no `uid`
     * we stage a fresh ScheduledAgent (a new UID is generated in its
     * `init()`) so the form has stable values to bind to — actual
     * persistence is deferred to {@see actionSave}.
     */
    public function actionEdit(?string $uid = null): Response
    {
        $settings = $this->settings();
        $agent = null;

        if ($uid !== null && $uid !== '') {
            foreach ($settings->getScheduledAgents() as $candidate) {
                if ($candidate->uid === $uid) {
                    $agent = $candidate;
                    break;
                }
            }

            if ($agent === null) {
                throw new NotFoundHttpException(Craft::t('craft-ai', 'Scheduled agent not found.'));
            }
        }

        $agent ??= new ScheduledAgent();
        $isNew = $uid === null || $uid === '';

        return $this->renderTemplate('craft-ai/scheduled-agents/_edit', [
            'agent' => $agent,
            'isNew' => $isNew,
            'frequencyChoices' => ScheduledAgent::frequencyChoices(),
            'dayOfWeekChoices' => ScheduledAgent::dayOfWeekChoices(),
            'runs' => $isNew ? [] : $this->recentRuns($agent->uid),
            'nextRun' => $isNew ? null : $this->nextRun($agent->uid),
        ]);
    }

    /**
     * Persist a single scheduled agent. We re-emit the full settings
     * payload because Craft's project-config write at
     * `plugins.craft-ai.settings` replaces the whole subtree — see the
     * class docblock.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $this->settings();

        $uid = $this->stringParam('uid');

        $incoming = ScheduledAgent::fromArray([
            // Empty uid means "new" — fromArray will mint one.
            'uid' => $uid !== '' ? $uid : null,
            'name' => $this->stringParam('name'),
            'prompt' => $this->stringParam('prompt'),
            'frequency' => $this->stringParam('frequency'),
            'minute' => $this->stringParam('minute', '0'),
            'time' => $this->stringParam('time', '09:00'),
            'dayOfWeek' => $this->stringParam('dayOfWeek', '1'),
            'dayOfMonth' => $this->stringParam('dayOfMonth', '1'),
            'runDate' => $this->stringParam('runDate'),
            'cronExpression' => $this->stringParam('cronExpression'),
            'enabled' => $this->getBoolBodyParam('enabled'),
            'userId' => $this->resolveRunAsUserId($settings, $uid),
        ]);

        // Validate the single agent first so we can route field-level
        // errors back to the edit form. The aggregate Settings validator
        // also catches this, but its errors are flattened into a single
        // attribute and lose the per-field detail.
        if (! $incoming->validate()) {
            return $this->renderFailure($incoming, $uid === '');
        }

        // Splice the incoming row into the existing list, preserving
        // order. If we don't find a match by uid, treat it as an append.
        $existing = $settings->getScheduledAgents();
        $rows = [];
        $matched = false;
        foreach ($existing as $agent) {
            if (! $matched && $agent->uid === $incoming->uid) {
                $rows[] = $incoming->toConfigArray();
                $matched = true;
            } else {
                $rows[] = $agent->toConfigArray();
            }
        }
        if (! $matched) {
            $rows[] = $incoming->toConfigArray();
        }

        $payload = [
            'automations' => $settings->automations,
            'scheduledAgents' => $rows,
            // Re-emit the *raw* commands payload — getCommands() would
            // materialize seeded defaults whose hardcoded UIDs we don't
            // want to bake into project config until the user actually
            // edits one.
            'commands' => $settings->commands,
        ];

        $ok = Craft::$app->getPlugins()->savePluginSettings($plugin, $payload);
        if (! $ok) {
            foreach ($settings->getErrors('scheduledAgents') as $msg) {
                $incoming->addError('prompt', $msg);
            }
            return $this->renderFailure($incoming, $uid === '');
        }

        // The schedule definition may have changed; drop the precomputed
        // pending slot so the dispatcher re-derives it on the next tick.
        // Terminal rows (the run history) are left intact.
        ScheduledRunRecord::deleteAll([
            'scheduledAgentUid' => $incoming->uid,
            'status' => ScheduledRunRecord::STATUS_PENDING,
        ]);

        $this->setSuccessFlash(Craft::t('craft-ai', 'Scheduled agent saved.'));
        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ai'));
    }

    /**
     * Remove a single scheduled agent, along with its run state/history —
     * orphaned rows for a definition that no longer exists would never
     * surface anywhere. Same project-config replacement caveat as
     * {@see actionSave}.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $uid = $this->stringParam('uid');
        $plugin = Plugin::getInstance();
        $settings = $this->settings();

        $rows = [];
        foreach ($settings->getScheduledAgents() as $agent) {
            if ($agent->uid !== $uid) {
                $rows[] = $agent->toConfigArray();
            }
        }

        $payload = [
            'automations' => $settings->automations,
            'scheduledAgents' => $rows,
            'commands' => $settings->commands,
        ];

        Craft::$app->getPlugins()->savePluginSettings($plugin, $payload);

        ScheduledRunRecord::deleteAll(['scheduledAgentUid' => $uid]);

        $this->setSuccessFlash(Craft::t('craft-ai', 'Scheduled agent deleted.'));
        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ai'));
    }

    /**
     * The run-as identity for the row being saved. New agents are owned
     * by the admin creating them; edits preserve the original creator as
     * long as that user still exists, and otherwise transfer ownership to
     * the editing admin (the recovery path the dispatcher's error rows
     * point users at).
     */
    private function resolveRunAsUserId(Settings $settings, string $uid): ?int
    {
        $currentUserId = Craft::$app->getUser()->getIdentity()?->id;
        $currentUserId = $currentUserId !== null ? (int) $currentUserId : null;

        if ($uid === '') {
            return $currentUserId;
        }

        foreach ($settings->getScheduledAgents() as $agent) {
            if ($agent->uid !== $uid) {
                continue;
            }

            $ownerExists = $agent->userId !== null
                && Craft::$app->getUsers()->getUserById($agent->userId) !== null;

            return $ownerExists ? $agent->userId : $currentUserId;
        }

        return $currentUserId;
    }

    /**
     * Most recent adjudicated slots (newest first) for the edit screen's
     * run-history table. Pending rows are excluded — the next slot
     * renders separately via {@see nextRun}.
     *
     * @return list<array{status: string, detail: ?string, sessionId: ?string, scheduledFor: ?\DateTime}>
     */
    private function recentRuns(string $uid): array
    {
        $records = ScheduledRunRecord::find()
            ->where(['scheduledAgentUid' => $uid])
            ->andWhere(['!=', 'status', ScheduledRunRecord::STATUS_PENDING])
            ->orderBy(['scheduledFor' => SORT_DESC])
            ->limit(10)
            ->all();

        $runs = [];
        foreach ($records as $record) {
            /** @var ScheduledRunRecord $record */
            $parsed = DateTimeHelper::toDateTime($record->scheduledFor);
            $runs[] = [
                'status' => $record->status,
                'detail' => $record->detail,
                'sessionId' => $record->sessionId,
                'scheduledFor' => $parsed === false ? null : $parsed,
            ];
        }

        return $runs;
    }

    private function nextRun(string $uid): ?\DateTime
    {
        $pending = ScheduledRunRecord::findOne([
            'scheduledAgentUid' => $uid,
            'status' => ScheduledRunRecord::STATUS_PENDING,
        ]);

        if ($pending === null) {
            return null;
        }

        $parsed = DateTimeHelper::toDateTime($pending->scheduledFor);

        return $parsed === false ? null : $parsed;
    }

    private function settings(): Settings
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (! $settings instanceof Settings) {
            throw new \RuntimeException('craft-ai: expected Settings model, got ' . (is_object($settings) ? get_class($settings) : gettype($settings)));
        }

        return $settings;
    }

    private function stringParam(string $name, string $default = ''): string
    {
        $value = $this->request->getBodyParam($name, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Re-render the edit form with the user's in-flight edits so they
     * don't lose work on a validation failure. We render directly
     * rather than relying on routeParams + URL manager re-resolution
     * because the save URL never has the form rendered against it —
     * the GET edit URL does.
     */
    private function renderFailure(ScheduledAgent $agent, bool $isNew): Response
    {
        $this->setFailFlash(Craft::t('craft-ai', 'Couldn’t save the scheduled agent.'));

        return $this->renderTemplate('craft-ai/scheduled-agents/_edit', [
            'agent' => $agent,
            'isNew' => $isNew,
            'frequencyChoices' => ScheduledAgent::frequencyChoices(),
            'dayOfWeekChoices' => ScheduledAgent::dayOfWeekChoices(),
            'runs' => $isNew ? [] : $this->recentRuns($agent->uid),
            'nextRun' => null,
        ]);
    }
}
