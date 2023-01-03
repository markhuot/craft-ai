<?php

namespace markhuot\craftai;

use craft\elements\Asset;
use craft\web\Application;
use craft\web\UrlManager;
use markhuot\craftai\base\Plugin;
use markhuot\craftai\listeners\AddBodyParamObjectBehavior;
use markhuot\craftai\listeners\DefineAssetMetaFieldsHtml;
use markhuot\craftai\listeners\redactor\DefineRedactorConfig;
use markhuot\craftai\listeners\redactor\RegisterPluginPaths;
use markhuot\craftai\listeners\RegisterCpUrlRules;
use markhuot\craftai\twig\Extension;
use function markhuot\openai\helpers\listen;

class Ai extends Plugin
{
    public function init()
    {
        parent::init();

        listen(
            fn() => [Application::class, Application::EVENT_BEFORE_REQUEST, AddBodyParamObjectBehavior::class],
            fn() => [UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, RegisterCpUrlRules::class],
            fn() => [\craft\redactor\Field::class, \craft\redactor\Field::EVENT_REGISTER_PLUGIN_PATHS, RegisterPluginPaths::class],
            fn() => [\craft\redactor\Field::class, \craft\redactor\Field::EVENT_DEFINE_REDACTOR_CONFIG, DefineRedactorConfig::class],
            fn() => [Asset::class, Asset::EVENT_DEFINE_META_FIELDS_HTML, DefineAssetMetaFieldsHtml::class],
        );

        \Craft::$app->view->registerTwigExtension(new Extension);
    }
}
