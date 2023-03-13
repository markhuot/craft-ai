<?php

namespace markhuot\craftai;

use craft\base\Element;
use craft\elements\Asset;
use craft\web\Application;
use craft\web\UrlManager;
use markhuot\craftai\base\Plugin;
use markhuot\craftai\listeners\AddAiField;
use markhuot\craftai\listeners\AddAssetSidebarWidgets;
use markhuot\craftai\listeners\AddBodyParamObjectBehavior;
use markhuot\craftai\listeners\GenerateEmbeddings;
use markhuot\craftai\listeners\redactor\DefineRedactorConfig;
use markhuot\craftai\listeners\redactor\RegisterPluginPaths;
use markhuot\craftai\listeners\RegisterCpUrlRules;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\Settings;
use markhuot\craftai\twig\Extension;
use function markhuot\openai\helpers\listen;

/**
 * @method static self getInstance()
 * @method Settings getSettings()
 */
class Ai extends Plugin
{
    public function init()
    {
        parent::init();

        listen(
            fn () => [Application::class, Application::EVENT_BEFORE_REQUEST, AddBodyParamObjectBehavior::class],
            fn () => [UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, RegisterCpUrlRules::class],
            fn () => [Asset::class, Asset::EVENT_DEFINE_SIDEBAR_HTML, AddAssetSidebarWidgets::class],
            fn () => [Element::class, Element::EVENT_AFTER_PROPAGATE, GenerateEmbeddings::class],
            // fn() => [Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, AddAiField::class],
            fn () => [\craft\redactor\Field::class, \craft\redactor\Field::EVENT_REGISTER_PLUGIN_PATHS, RegisterPluginPaths::class],
            fn () => [\craft\redactor\Field::class, \craft\redactor\Field::EVENT_DEFINE_REDACTOR_CONFIG, DefineRedactorConfig::class],
        );

        Backend::fake($this->getSettings()->useFakes);

        \Craft::$app->view->registerTwigExtension(new Extension);
    }
}
