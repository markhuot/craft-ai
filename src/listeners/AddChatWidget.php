<?php

namespace markhuot\craftai\listeners;

use craft\controllers\ElementsController;
use markhuot\craftai\assetbundles\CraftAi;
use markhuot\craftai\controllers\ChatController;
use markhuot\craftai\features\Chat;
use markhuot\craftai\models\Backend;
use function markhuot\openai\helpers\request;
use function markhuot\openai\helpers\session;
use function markhuot\openai\helpers\view;

class AddChatWidget
{
    public function handle(): void
    {
        if (! request()->isCpRequest) {
            return;
        }

        if (! Backend::can(Chat::class)) {
            return;
        }

        view()->registerAssetBundle(CraftAi::class);

        $elementId = null;
        if (is_a(\Craft::$app->controller, ElementsController::class)) {
            $elementId = \Craft::$app->controller->element->id; // @phpstan-ignore-line Ignored because ID isn't on the element interface, but in most cases it'll be there anyway
        }

        echo view()->renderTemplate('ai/_chat/widget', [
            'messages' => session()->get(ChatController::CACHE_KEY) ?? [],
            'elementId' => $elementId,
        ]);
    }
}
