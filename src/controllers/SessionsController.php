<?php

namespace markhuot\craftai\controllers;

use Craft;
use craft\web\Controller;
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
}
