<?php

namespace markhuot\craftai\controllers;

use craft\web\Controller;
use markhuot\craftai\records\MessageRecord;
use yii\web\Response;

class MessagesController extends Controller
{
    public function actionIndex(): Response
    {
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
}
