<?php

namespace markhuot\craftai\controllers;

use markhuot\craftai\db\AskQuery;
use markhuot\craftai\models\AskPostRequest;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\web\Controller;

/**
 * @property Request $request
 */
class AskController extends Controller
{
    public function actionIndex()
    {
        $prompt = $this->request->getQueryParam('prompt', '');

        return $this->renderTemplate('ai/_ask/index', [
            'prompt' => $this->request->getQueryParam('prompt', ''),
            'answer' => \Craft::$container->get(AskQuery::class)->prompt($prompt)->answer(),
        ]);
    }

    public function actionAsk()
    {
        $data = $this->request->getBodyParamObject(AskPostRequest::class);

        return $this->redirect('ai/ask?prompt='.urlencode($data->prompt));
    }
}
