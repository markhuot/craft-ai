<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use yii\web\Response;

class SessionsController extends Controller
{
    public function actionIndex(): Response
    {
        $rows = MessageRecord::find()
            ->select([
                'sessionId',
                'messageCount' => 'COUNT(*)',
                'firstMessage' => 'MIN([[dateCreated]])',
                'lastMessage' => 'MAX([[dateCreated]])',
            ])
            ->groupBy('sessionId')
            ->orderBy(['lastMessage' => SORT_DESC])
            ->asArray()
            ->all();

        return $this->renderTemplate('craft-ai/sessions/index', [
            'sessions' => $rows,
        ]);
    }

    public function actionNew(): Response
    {
        $this->requirePostRequest();

        $uuid = StringHelper::UUID();

        return $this->redirect(UrlHelper::cpUrl("ai/session/{$uuid}"));
    }

    public function actionView(?string $uuid = null): Response
    {
        $uuid = $uuid ?? $this->request->getRequiredParam('uuid');
        /** @var list<MessageRecord> $records */
        $records = MessageRecord::find()
            ->where(['sessionId' => $uuid])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $messages = array_map(static function (MessageRecord $record): array {
            /** @var list<array<string, mixed>> $content */
            $content = json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);

            return [
                'id' => $record->id,
                'role' => $record->role,
                'content' => $content,
                'dateCreated' => $record->dateCreated,
            ];
        }, $records);

        return $this->renderTemplate('craft-ai/sessions/view', [
            'sessionId' => $uuid,
            'messages' => $messages,
        ]);
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $message = trim((string) $this->request->getRequiredBodyParam('message'));

        if ($message === '') {
            return $this->asJson(['queued' => false]);
        }

        Craft::$app->getQueue()->push(new AgentJob([
            'sessionId' => $sessionId,
            'userMessage' => $message,
        ]));

        return $this->asJson(['queued' => true]);
    }
}
