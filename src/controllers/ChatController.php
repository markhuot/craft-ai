<?php

namespace markhuot\craftai\controllers;

use markhuot\craftai\actions\GetElementKeywords;
use markhuot\craftai\features\Chat;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\ChatMessagePostRequest;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\web\Controller;

/**
 * @property Request $request
 */
class ChatController extends Controller
{
    const CACHE_KEY = 'craftai-messages';

    public function actionIndex()
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

    public function actionSend()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(ChatMessagePostRequest::class);

        $keywords = '';
        if ($data->elementId) {
            $element = \Craft::$app->elements->getElementById($data->elementId);
            $keywords = "\n\nAdditional Context: " . \Craft::$container->get(GetElementKeywords::class)->handle($element)->join(' ');
        }

        $messages = collect(\Craft::$app->session->get(static::CACHE_KEY) ?? [[
            'role' => 'system',
            'content' => '',
        ]]);
        $messages = $messages->map(fn ($message) => $message['role'] === 'system' ? [
            'role' => 'system',
            'content' => $data->personality . $keywords,
        ] : $message)->toArray();
        $messages[] = [
            'role' => 'user',
            'content' => $data->message,
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => Backend::for(Chat::class)->chat($messages)->message,
        ];
        \Craft::$app->session->set(static::CACHE_KEY, $messages);

        return $this->response(
            html: fn () => $this->redirect('ai/chat'),
            json: [
                'success' => true,
                'messageMarkup' => \Craft::$app->view->renderTemplate('ai/_chat/_widget-messages', [
                    'messages' => $messages,
                ])
            ],
        );
    }

    public function actionClear()
    {
        \Craft::$app->session->remove(static::CACHE_KEY);

        return $this->response(
            html: fn () => $this->redirect('ai/chat'),
            json: [
                'success' => true,
                'messageMarkup' => \Craft::$app->view->renderTemplate('ai/_chat/_widget-messages', [
                    'messages' => [],
                ])
            ],
        );
    }
}
