<?php

namespace markhuot\craftai\listeners;

use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\events\DefineHtmlEvent;
use markhuot\craftai\features\Caption;
use markhuot\craftai\models\Backend;
use function markhuot\openai\helpers\view;
use yii\base\Event;

class AddAssetSidebarWidgets implements ListenerInterface
{
    /**
     * @param  DefineHtmlEvent  $event
     */
    public function handle(Event $event): void
    {
        if (Backend::can(Caption::class)) {
            /** @var Asset $asset */
            $asset = $event->sender;
            $caption = (new Query)->select('caption')->from(Table::ASSETS)->where(['id' => $asset->id])->scalar();
            $event->html .= view()->renderTemplate('ai/_cp/assets/sidebar', [
                'asset' => $asset,
                'caption' => $caption,
            ]);
        }
    }
}
