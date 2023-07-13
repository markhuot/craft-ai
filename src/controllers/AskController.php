<?php

namespace markhuot\craftai\controllers;

use markhuot\craftai\db\AskQuery;
use function markhuot\craftai\helpers\app;
use markhuot\craftai\models\AskPostRequest;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\web\Controller;
use yii\web\Response;

/**
 * @property Request $request
 */
class AskController extends Controller
{
    public function actionIndex(): Response
    {
        $prompt = $this->request->getQueryParamString('prompt');

        return $this->renderTemplate('ai/_ask/index', [
            'prompt' => $this->request->getQueryParam('prompt', ''),
            'answer' => app(AskQuery::class)->prompt($prompt)->answer(),
        ]);
    }

    public function actionAsk(): Response
    {
        $data = $this->request->getBodyParamObject(AskPostRequest::class);

        return $this->redirect('ai/ask?prompt='.urlencode($data->prompt));
    }
}
