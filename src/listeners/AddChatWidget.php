<?php

namespace markhuot\craftai\listeners;

use craft\controllers\ElementsController;
use craft\web\Request;
use craft\web\View;
use markhuot\craftai\assetbundles\CraftAi;
use markhuot\craftai\controllers\ChatController;
use markhuot\craftai\features\Chat;
use markhuot\craftai\models\Backend;
use yii\base\Event;

class AddChatWidget
{
    public function handle(Event $event): void
    {
        /** @var Request $request */
        $request = \Craft::$app->request;

        /** @var View $view */
        $view = \Craft::$app->view;

        if (! $request->isCpRequest) {
            return;
        }

        if (! Backend::can(Chat::class)) {
            return;
        }

        $view->registerAssetBundle(CraftAi::class);

        $elementId = null;
        if (is_a(\Craft::$app->controller, ElementsController::class)) {
            $elementId = \Craft::$app->controller->element->id; // @phpstan-ignore-line Ignored because ID isn't on the element interface, but in most cases it'll be there anyway
        }

        echo $view->renderTemplate('ai/_chat/widget', [
            'messages' => \Craft::$app->session->get(ChatController::CACHE_KEY) ?? [],
            'elementId' => $elementId,
        ]);
    }
}
