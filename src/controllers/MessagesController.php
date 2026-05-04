<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\web\Controller;
use markhuot\craftai\agent\AgentLoop;
use markhuot\craftai\queue\AgentJob;
use markhuot\craftai\records\MessageRecord;
use yii\web\Response;

class MessagesController extends Controller
{
    public array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireLogin();

        $sessionId = $this->request->getRequiredQueryParam('sessionId');
        $afterParam = $this->request->getQueryParam('after', '0');
        $after = is_numeric($afterParam) ? (int) $afterParam : 0;

        /** @var list<MessageRecord> $records */
        $records = MessageRecord::find()
            ->where(['sessionId' => $sessionId])
            ->andWhere(['>', 'id', $after])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $messages = array_map(static fn (MessageRecord $record): array => [
            'id' => $record->id,
            'role' => $record->role,
            'content' => json_decode($record->content, true, 512, JSON_THROW_ON_ERROR),
            'dateCreated' => $record->dateCreated,
        ], $records);

        return $this->asJson($messages);
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $sessionId = $this->request->getRequiredBodyParam('sessionId');
        $userMessage = $this->request->getRequiredBodyParam('message');

        if (! is_string($sessionId) || ! is_string($userMessage)) {
            throw new \yii\web\BadRequestHttpException('sessionId and message must be strings.');
        }
        $async = (bool) $this->request->getBodyParam('async', false);

        if ($async) {
            $identity = Craft::$app->getUser()->getIdentity();
            Craft::$app->getQueue()->push(new AgentJob([
                'sessionId' => $sessionId,
                'userMessage' => $userMessage,
                'userId' => $identity !== null ? (int) $identity->id : null,
            ]));

            return $this->asJson(['queued' => true, 'sessionId' => $sessionId]);
        }

        /** @var AgentLoop $loop */
        $loop = Craft::$container->get(AgentLoop::class);
        $loop->run($sessionId, $userMessage);

        return $this->asJson(['ok' => true, 'sessionId' => $sessionId]);
    }
}
