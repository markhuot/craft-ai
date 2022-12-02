<?php

namespace markhuot\craftai;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\Application;
use craft\web\Request;
use craft\web\UrlManager;
use markhuot\craftai\behaviors\BodyParamObjectBehavior;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\Settings;
use markhuot\craftai\twig\Extension;
use yii\base\Event;

class Ai extends Plugin
{
    public bool $hasCpSettings = true;

    public function init()
    {
        Craft::setAlias('@ai', $this->getBasePath());
        $this->controllerNamespace = 'markhuot\\craftai\\controllers';

        Event::on(
            Application::class,
            Application::EVENT_BEFORE_REQUEST,
            function (Event $event)
            {
                \Craft::$app->request->attachBehaviors(['bodyParamObject' => BodyParamObjectBehavior::class]);
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event)
            {
                $event->rules['ai/backends'] = 'ai/backends/index';
                $event->rules['ai/backend/<id:\d+>'] = 'ai/backends/edit';
                $event->rules['ai/backend/create/<type:[a-z-]+>'] = 'ai/backends/create';
            }
        );

        if (class_exists(\craft\redactor\Field::class)) {
            Event::on(
                \craft\redactor\Field::class,
                \craft\redactor\Field::EVENT_REGISTER_PLUGIN_PATHS,
                function (\craft\redactor\events\RegisterPluginPathsEvent $event)
                {
                    $event->paths[] = __DIR__ . '/redactor/';
                }
            );

            \craft\redactor\Field::registerRedactorPlugin('craftai-complete');
            \craft\redactor\Field::registerRedactorPlugin('craftai-edit');

            Event::on(
                \craft\redactor\Field::class,
                \craft\redactor\Field::EVENT_DEFINE_REDACTOR_CONFIG,
                function (\craft\redactor\events\ModifyRedactorConfigEvent $event)
                {
                    if (empty($event->config)) {
                        $event->config['plugins'][] = 'craftai-complete';
                        $event->config['plugins'][] = 'craftai-edit';
                    }
                }
            );
        }

        \Craft::$app->view->registerTwigExtension(new Extension);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings;
    }

    protected function settingsHtml(): ?string
    {
        return \Craft::$app->view->renderTemplate('ai/settings', [
            'settings' => $this->getSettings(),
            'backends' => Backend::find()->all(),
        ]);
    }
}
