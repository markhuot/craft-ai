<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\models\Command;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Dedicated CP screens for editing a single slash command.
 *
 * The plugin settings page lists commands in a read-only table and links
 * here for any non-trivial edit, because a slash-command prompt can grow
 * past what a one-line cell in the settings table can comfortably hold.
 *
 * Saves here round-trip through {@see \craft\services\Plugins::savePluginSettings()}
 * just like the parent settings form does — including a full re-emit of
 * `automations` to avoid wiping the other half of the settings tree (the
 * project-config `set()` at `plugins.craft-ai.settings` is a full replace,
 * not a merge).
 */
class CommandsController extends Controller
{
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
     * Render the edit screen for a single slash command. With no `uid`,
     * we stage a fresh Command (a new UID is generated in its `init()`) so
     * the form has stable values to bind to — actual persistence is
     * deferred to {@see actionSave}.
     */
    public function actionEdit(?string $uid = null): Response
    {
        $settings = $this->settings();
        $command = null;

        if ($uid !== null && $uid !== '') {
            foreach ($settings->getCommands() as $cmd) {
                if ($cmd->uid === $uid) {
                    $command = $cmd;
                    break;
                }
            }

            if ($command === null) {
                throw new NotFoundHttpException(Craft::t('craft-ai', 'Slash command not found.'));
            }
        }

        $command ??= new Command();
        $isNew = $uid === null || $uid === '';

        return $this->renderTemplate('craft-ai/commands/_edit', [
            'command' => $command,
            'isNew' => $isNew,
        ]);
    }

    /**
     * Persist a single slash command. We re-emit the full settings payload
     * (commands + automations) because Craft's project-config write at
     * `plugins.craft-ai.settings` replaces the whole subtree — passing only
     * `commands` would silently wipe the saved automations.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $this->settings();

        $uid = (string) $this->request->getBodyParam('uid', '');
        $name = (string) $this->request->getBodyParam('name', '');
        $prompt = (string) $this->request->getBodyParam('prompt', '');
        $enabled = (bool) $this->request->getBodyParam('enabled', false);

        $incoming = Command::fromArray([
            // Empty uid means "new" — fromArray will mint one.
            'uid' => $uid !== '' ? $uid : null,
            'name' => $name,
            'prompt' => $prompt,
            'enabled' => $enabled,
        ]);

        // Validate the single command first so we can route field-level
        // errors back to the edit form. The aggregate Settings::validate
        // also catches this, but its errors are flattened into a single
        // attribute and lose the per-field detail.
        if (! $incoming->validate()) {
            return $this->renderFailure($incoming, $uid === '');
        }

        // Splice the incoming row into the existing list, preserving order.
        // If we don't find a match by uid, treat it as an append.
        $existing = $settings->getCommands();
        $rows = [];
        $matched = false;
        foreach ($existing as $cmd) {
            if (! $matched && $cmd->uid === $incoming->uid) {
                $rows[] = $incoming->toConfigArray();
                $matched = true;
            } else {
                $rows[] = $cmd->toConfigArray();
            }
        }
        if (! $matched) {
            $rows[] = $incoming->toConfigArray();
        }

        $payload = [
            'automations' => $settings->automations,
            'commands' => $rows,
        ];

        $ok = Craft::$app->getPlugins()->savePluginSettings($plugin, $payload);
        if (! $ok) {
            // Surface aggregate errors from the Settings model — e.g., a
            // duplicate-name conflict that single-row validation missed.
            // setAttributes ran inside savePluginSettings, so model errors
            // live on the Settings instance, not on `$incoming` — copy
            // them across so the edit form can render them inline.
            foreach ($settings->getErrors('commands') as $msg) {
                $incoming->addError('prompt', $msg);
            }
            return $this->renderFailure($incoming, $uid === '');
        }

        $this->setSuccessFlash(Craft::t('craft-ai', 'Slash command saved.'));
        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ai'));
    }

    /**
     * Remove a single slash command. Same project-config replacement
     * caveat as {@see actionSave} — we re-emit automations alongside the
     * trimmed command list.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $uid = (string) $this->request->getRequiredBodyParam('uid');
        $plugin = Plugin::getInstance();
        $settings = $this->settings();

        $rows = [];
        foreach ($settings->getCommands() as $cmd) {
            if ($cmd->uid !== $uid) {
                $rows[] = $cmd->toConfigArray();
            }
        }

        $payload = [
            'automations' => $settings->automations,
            'commands' => $rows,
        ];

        Craft::$app->getPlugins()->savePluginSettings($plugin, $payload);

        $this->setSuccessFlash(Craft::t('craft-ai', 'Slash command deleted.'));
        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ai'));
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

    /**
     * Re-render the edit form with the user's in-flight edits so they
     * don't lose work on a validation failure. We render directly rather
     * than relying on routeParams + URL manager re-resolution because the
     * save URL never has the form rendered against it — the GET edit URL
     * does. A direct render keeps the failed model and its inline errors
     * on screen without a round-trip.
     */
    private function renderFailure(Command $command, bool $isNew): Response
    {
        $this->setFailFlash(Craft::t('craft-ai', 'Couldn’t save the slash command.'));

        return $this->renderTemplate('craft-ai/commands/_edit', [
            'command' => $command,
            'isNew' => $isNew,
        ]);
    }
}
