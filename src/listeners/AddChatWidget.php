<?php

namespace markhuot\craftai\listeners;

use craft\controllers\ElementsController;
use craft\web\Request;
use markhuot\craftai\assetbundles\CraftAi;
use markhuot\craftai\controllers\ChatController;
use markhuot\craftai\features\Chat;
use markhuot\craftai\models\Backend;
use yii\base\Event;

class AddChatWidget
{
    function handle(Event $event) {
        if (!Backend::can(Chat::class)) {
            return;
        }

        \Craft::$app->view->registerAssetBundle(CraftAi::class);

        $elementId = null;
        if (is_a(\Craft::$app->controller, ElementsController::class)) {
            $elementId = \Craft::$app->controller->element->id;
        }

        echo \Craft::$app->view->renderTemplate('ai/_chat/widget', [
            'messages' => \Craft::$app->session->get(ChatController::CACHE_KEY) ?? [],
            'elementId' => $elementId,
        ]);
    }
}
