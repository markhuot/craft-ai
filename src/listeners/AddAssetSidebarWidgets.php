<?php

namespace markhuot\craftai\listeners;

use craft\db\Query;
use craft\db\Table;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineHtmlEvent;
use markhuot\craftai\behaviors\AssetSidebarWidgetBehavior;
use markhuot\craftai\features\Caption;
use markhuot\craftai\models\Backend;

class AddAssetSidebarWidgets
{
    function handle(DefineHtmlEvent $event)
    {
        if (Backend::for(Caption::class, true)) {
            $asset = $event->sender;
            $caption = (new Query)->select('caption')->from(Table::ASSETS)->where(['id' => $asset->id])->scalar();
            $event->html .= \Craft::$app->view->renderTemplate('ai/_cp/assets/sidebar', [
                'asset' => $asset,
                'caption' => $caption,
            ]);
        }
    }
}
