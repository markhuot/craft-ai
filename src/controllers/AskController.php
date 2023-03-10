<?php

namespace markhuot\craftai\controllers;

use markhuot\craftai\db\AskQuery;
use markhuot\craftai\features\GenerateEmbeddings;
use markhuot\craftai\models\AskPostRequest;
use markhuot\craftai\models\Backend;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\web\Controller;

/**
 * @property Request $request
 */
class AskController extends Controller
{
    function actionIndex()
    {
        $prompt = $this->request->getQueryParam('prompt', '');

        return $this->renderTemplate('ai/_ask/index', [
            'prompt' => $this->request->getQueryParam('prompt', ''),
            'answer' => \Craft::$container->get(AskQuery::class)->prompt($prompt)->answer(),
        ]);
    }

    function actionAsk()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(AskPostRequest::class);

        return $this->redirect('ai/ask?prompt='.urlencode($data->prompt));
    }
}
