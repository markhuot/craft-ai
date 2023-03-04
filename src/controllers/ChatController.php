<?php

namespace markhuot\craftai\controllers;

use craft\elements\Asset;
use craft\web\Controller;
use markhuot\craftai\actions\CreateAssetsForImages;
use markhuot\craftai\features\Chat;
use markhuot\craftai\features\GenerateImage;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\ChatMessagePostRequest;
use markhuot\craftai\models\GenerateImagePostRequest;
use markhuot\craftai\stubs\Request;

/**
 * @property Request $request
 */
class ChatController extends Controller
{
    function actionIndex()
    {
        $messages = collect(\Craft::$app->session->get('craftai-messages', []));
        $personality = $messages->where('role', '=', 'system')->first()['content'] ??
            'You are a friendly chatbot. You try to answer every question authoritatively. If you do not know the answer, you will say so.';
        $messages = $messages->filter(fn ($message) => $message['role'] != 'system');

        return $this->renderTemplate('ai/_chat/index', [
            'backends' => Backend::find()->all(),
            'messages' => $messages,
            'personality' => $personality,
        ]);
    }

    function actionSend()
    {
        $sessionKey = 'craftai-messages';
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(ChatMessagePostRequest::class);

        $messages = \Craft::$app->session->get($sessionKey) ?? [[
            'role' => 'system',
            'content' => $data->personality,
        ]];
        $messages[] = [
            'role' => 'user',
            'content' => $data->message,
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => Backend::for(Chat::class)->chat($messages)->message,
        ];
        \Craft::$app->session->set($sessionKey, $messages);

        return $this->redirect('ai/chat');
    }

    function actionClear()
    {
        \Craft::$app->session->remove('craftai-messages');

        return $this->redirect('ai/chat');
    }
}
