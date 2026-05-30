<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\models\Automation;
use markhuot\craftai\models\Settings;
use markhuot\craftai\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Dedicated CP screens for editing a single automation rule.
 *
 * Mirrors {@see CommandsController}: the plugin settings page lists
 * automations in a read-only table and links here for actual edits, so
 * the prompt textarea has room to breathe instead of being clamped to a
 * one- or two-row cell.
 *
 * Saves re-emit the full settings payload (automations + commands)
 * because Craft writes plugin settings via a project-config `set()` at
 * `plugins.craft-ai.settings`, which replaces the whole subtree. If we
 * passed only `automations`, the saved slash commands would be wiped.
 */
class AutomationsController extends Controller
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
     * Render the edit screen for a single automation. With no `uid` we
     * stage a fresh Automation (a new UID is generated in its `init()`)
     * so the form has stable values to bind to — actual persistence is
     * deferred to {@see actionSave}.
     */
    public function actionEdit(?string $uid = null): Response
    {
        $settings = $this->settings();
        $automation = null;

        if ($uid !== null && $uid !== '') {
            foreach ($settings->getAutomations() as $auto) {
                if ($auto->uid === $uid) {
                    $automation = $auto;
                    break;
                }
            }

            if ($automation === null) {
                throw new NotFoundHttpException(Craft::t('craft-ai', 'Automation not found.'));
            }
        }

        $automation ??= new Automation();
        $isNew = $uid === null || $uid === '';

        return $this->renderTemplate('craft-ai/automations/_edit', [
            'automation' => $automation,
            'isNew' => $isNew,
            'eventChoices' => Automation::eventChoices(),
            'scopeByEvent' => $this->scopeByEvent(),
            'sectionOptions' => $this->sectionOptions(),
            'volumeOptions' => $this->volumeOptions(),
        ]);
    }

    /**
     * Persist a single automation. We re-emit the full settings payload
     * (automations + commands) because Craft's project-config write at
     * `plugins.craft-ai.settings` replaces the whole subtree — passing
     * only `automations` would silently wipe the saved slash commands.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $this->settings();

        $uid = $this->stringParam('uid');
        $name = $this->stringParam('name');
        $event = $this->stringParam('event');
        $sectionHandle = $this->stringParam('sectionHandle');
        $volumeHandle = $this->stringParam('volumeHandle');
        $prompt = $this->stringParam('prompt');
        $enabled = $this->getBoolBodyParam('enabled');

        $incoming = Automation::fromArray([
            // Empty uid means "new" — fromArray will mint one.
            'uid' => $uid !== '' ? $uid : null,
            'name' => $name,
            'event' => $event,
            'sectionHandle' => $sectionHandle,
            'volumeHandle' => $volumeHandle,
            'prompt' => $prompt,
            'enabled' => $enabled,
        ]);

        // Validate the single automation first so we can route field-
        // level errors back to the edit form. The aggregate Settings
        // validator also catches this, but its errors are flattened
        // into a single attribute and lose the per-field detail.
        if (! $incoming->validate()) {
            return $this->renderFailure($incoming, $uid === '');
        }

        // Splice the incoming row into the existing list, preserving
        // order. If we don't find a match by uid, treat it as an append.
        $existing = $settings->getAutomations();
        $rows = [];
        $matched = false;
        foreach ($existing as $auto) {
            if (! $matched && $auto->uid === $incoming->uid) {
                $rows[] = $incoming->toConfigArray();
                $matched = true;
            } else {
                $rows[] = $auto->toConfigArray();
            }
        }
        if (! $matched) {
            $rows[] = $incoming->toConfigArray();
        }

        $payload = [
            'automations' => $rows,
            // Re-emit the *raw* commands payload — getCommands() would
            // materialize seeded defaults whose hardcoded UIDs we don't
            // want to bake into project config until the user actually
            // edits one.
            'commands' => $settings->commands,
        ];

        $ok = Craft::$app->getPlugins()->savePluginSettings($plugin, $payload);
        if (! $ok) {
            // Surface aggregate errors from the Settings model. Errors
            // live on the Settings instance from the in-controller
            // setAttributes call, so copy them across to the incoming
            // model the edit form is rendering.
            foreach ($settings->getErrors('automations') as $msg) {
                $incoming->addError('prompt', $msg);
            }
            return $this->renderFailure($incoming, $uid === '');
        }

        $this->setSuccessFlash(Craft::t('craft-ai', 'Automation saved.'));
        return $this->redirect(UrlHelper::cpUrl('settings/plugins/craft-ai'));
    }

    /**
     * Remove a single automation. Same project-config replacement
     * caveat as {@see actionSave} — we re-emit the commands half
     * alongside the trimmed automation list.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $uid = $this->stringParam('uid');
        $plugin = Plugin::getInstance();
        $settings = $this->settings();

        $rows = [];
        foreach ($settings->getAutomations() as $auto) {
            if ($auto->uid !== $uid) {
                $rows[] = $auto->toConfigArray();
            }
        }

        $payload = [
            'automations' => $rows,
            'commands' => $settings->commands,
        ];

        Craft::$app->getPlugins()->savePluginSettings($plugin, $payload);

        $this->setSuccessFlash(Craft::t('craft-ai', 'Automation deleted.'));
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

    private function stringParam(string $name, string $default = ''): string
    {
        $value = $this->request->getBodyParam($name, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, ?string>
     */
    private function scopeByEvent(): array
    {
        $out = [];
        foreach (array_keys(Automation::eventChoices()) as $event) {
            $out[$event] = Automation::scopeFor($event);
        }
        return $out;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function sectionOptions(): array
    {
        $options = [['label' => Craft::t('craft-ai', '— Any section —'), 'value' => '']];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[] = ['label' => $section->name ?? '', 'value' => $section->handle ?? ''];
        }
        return $options;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function volumeOptions(): array
    {
        $options = [['label' => Craft::t('craft-ai', '— Any volume —'), 'value' => '']];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['label' => $volume->name ?? '', 'value' => $volume->handle ?? ''];
        }
        return $options;
    }

    /**
     * Re-render the edit form with the user's in-flight edits so they
     * don't lose work on a validation failure. We render directly
     * rather than relying on routeParams + URL manager re-resolution
     * because the save URL never has the form rendered against it —
     * the GET edit URL does.
     */
    private function renderFailure(Automation $automation, bool $isNew): Response
    {
        $this->setFailFlash(Craft::t('craft-ai', 'Couldn’t save the automation.'));

        return $this->renderTemplate('craft-ai/automations/_edit', [
            'automation' => $automation,
            'isNew' => $isNew,
            'eventChoices' => Automation::eventChoices(),
            'scopeByEvent' => $this->scopeByEvent(),
            'sectionOptions' => $this->sectionOptions(),
            'volumeOptions' => $this->volumeOptions(),
        ]);
    }
}
