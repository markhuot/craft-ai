<?php

namespace markhuot\craftai\listeners;

use Craft;
use craft\controllers\ElementsController;
use markhuot\craftai\actions\HandleChatMessagesInSession;
use markhuot\craftai\assetbundles\CraftAi;
use markhuot\craftai\controllers\ChatController;
use markhuot\craftai\features\Chat;
use markhuot\craftai\models\Backend;
use function markhuot\craftai\helpers\app;
use function markhuot\openai\helpers\web\auth;
use function markhuot\openai\helpers\web\request;
use function markhuot\openai\helpers\web\session;
use function markhuot\openai\helpers\web\view;

class AddChatWidget
{
    public function handle(): void
    {
        if (! request()->isCpRequest) {
            return;
        }

        if (! auth()->getIdentity()) {
            return;
        }

        if (! Backend::can(Chat::class)) {
            return;
        }

        view()->registerAssetBundle(CraftAi::class);

        $elementId = null;
        if (is_a(Craft::$app->controller, ElementsController::class)) {
            $elementId = Craft::$app->controller->element->id; // @phpstan-ignore-line Ignored because ID isn't on the element interface, but in most cases it'll be there anyway
        }

        echo view()->renderTemplate('ai/_chat/widget', [
            'messages' => app(HandleChatMessagesInSession::class)->get(),
            'elementId' => $elementId,
        ]);
    }
}
