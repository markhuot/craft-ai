<?php

namespace markhuot\craftai\controllers;

use craft\base\ElementInterface;
use markhuot\craftai\actions\GetElementKeywords;
use markhuot\craftai\actions\HandleChatMessagesInSession;
use markhuot\craftai\features\Chat;
use function markhuot\craftai\helpers\app;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\ChatMessagePostRequest;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\web\Controller;
use function markhuot\openai\helpers\throw_if;
use function markhuot\openai\helpers\web\elements;
use function markhuot\openai\helpers\web\view;
use yii\web\Response;

/**
 * @property Request $request
 */
class ChatController extends Controller
{
    public function actionIndex(): Response
    {
        $messages = app(HandleChatMessagesInSession::class)->get();
        $personality = $messages->where('role', '=', 'system')->first()['content'] ??
            'You are a friendly chatbot. You try to answer every question authoritatively. If you do not know the answer, you will say so.';
        $messages = $messages->filter(fn ($message) => $message['role'] != 'system');

        return $this->renderTemplate('ai/_chat/index', [
            'backends' => Backend::find()->all(),
            'messages' => $messages,
            'personality' => $personality,
        ]);
    }

    public function actionSend(): Response
    {
        $data = $this->request->getBodyParamObject(ChatMessagePostRequest::class);

        $keywords = '';
        if ($data->elementId) {
            $element = elements()->getElementById($data->elementId, ElementInterface::class);
            throw_if(! $element, 'No element found for '.$data->elementId);

            $keywords = "\n\nAdditional Context: ".app(GetElementKeywords::class)->handle($element)->join(' ');
        }

        $messages = app(HandleChatMessagesInSession::class)->get([[
            'role' => 'system',
            'content' => '',
        ]]);
        $messages = $messages->map(fn ($message) => $message['role'] === 'system' ? [
            'role' => 'system',
            'content' => $data->personality.$keywords,
        ] : $message)->toArray();
        $messages[] = [
            'role' => 'user',
            'content' => $data->message,
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => Backend::for(Chat::class)->chat($messages)->message,
        ];
        app(HandleChatMessagesInSession::class)->set($messages);

        return $this->response(
            html: fn () => $this->redirect('ai/chat'),
            json: [
                'success' => true,
                'messageMarkup' => view()->renderTemplate('ai/_chat/_widget-messages', [
                    'messages' => $messages,
                ]),
            ],
        );
    }

    public function actionClear(): Response
    {
        app(HandleChatMessagesInSession::class)->clear();

        return $this->response(
            html: fn () => $this->redirect('ai/chat'),
            json: [
                'success' => true,
                'messageMarkup' => view()->renderTemplate('ai/_chat/_widget-messages', [
                    'messages' => [],
                ]),
            ],
        );
    }
}
