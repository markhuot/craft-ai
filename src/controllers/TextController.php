<?php

namespace markhuot\craftai\controllers;

use craft\helpers\UrlHelper;
use craft\web\Controller;
use markhuot\craftai\features\Completion;
use markhuot\craftai\features\Edit;
use markhuot\craftai\models\Backend;
use markhuot\craftai\models\TextEditPostRequest;
use markhuot\craftai\stubs\Request;
use markhuot\craftai\models\TextCompletionPostRequest;

/**
 * @property Request $request
 */
class TextController extends \markhuot\craftai\web\Controller
{
    function actionIndex()
    {
        return $this->renderTemplate('ai/_text/index');
    }

    function actionComplete()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(TextCompletionPostRequest::class);

        $response = Backend::for(Completion::class)->completeText($data->content);

        return $this->response(
            json: fn () => $this->asJson(['text' => $response->text]),
            html: fn () => $this->flash('AI completion succeeded')->redirect(UrlHelper::prependCpTrigger('ai/text?' . http_build_query([
                'content' => $data->content,
                'completion' => $response->text,
            ]))),
        );
    }

    function actionEdit()
    {
        $this->requirePostRequest();
        $data = $this->request->getBodyParamObject(TextEditPostRequest::class);

        $response = Backend::for(Edit::class)->editText($data->input, $data->instructions);

        return $this->response(
            json: fn () => $this->asJson(['text' => $response->text]),
        );
    }
}
